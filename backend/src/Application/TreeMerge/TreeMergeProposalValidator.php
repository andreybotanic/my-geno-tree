<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Api\PersonViewFactory;
use App\Application\Person\PersonDataPolicy;
use App\Application\Tree\ChangeSet\MarriageChange;
use App\Application\Tree\ChangeSet\ParentChange;
use App\Application\Tree\ChangeSet\PersonChange;
use App\Application\Tree\ChangeSet\TreeChangeSet;
use App\Application\Tree\ChangeSet\TreeChangeSetProjector;
use App\Entity\TreeMergeDraft;
use App\Entity\Value\ParentRole;
use App\Repository\PersonRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class TreeMergeProposalValidator
{
    public const int MAX_QUOTE_LENGTH = 300;

    public function __construct(
        private PersonDataPolicy $data,
        private PersonRepository $people,
        private PersonViewFactory $views,
        private TreeChangeSetProjector $projector,
        private ClockInterface $clock,
        private TreeMergeChat $chat,
    ) {
    }

    public function validate(TreeMergeDraft $draft, mixed $value, bool $manual = false, bool $validateGraph = true): ValidatedTreeMergeProposal
    {
        $proposal = $this->object($value, ['people', 'parents', 'marriages', 'questions'], 'proposal');
        $personRows = $this->items($proposal['people'], 20, 'people');
        $parentRows = $this->items($proposal['parents'], 40, 'parents');
        $marriageRows = $this->items($proposal['marriages'], 20, 'marriages');
        $questions = $this->items($proposal['questions'], 30, 'questions');
        foreach ($questions as $index => $question) {
            if (!is_string($question) || '' === trim($question) || mb_strlen($question) > 1000) {
                $this->invalid('questions[' . $index . ']', 'question_invalid', 'Expected a nonempty question of at most 1000 characters.');
            }
        }
        $normalized = $changes = $references = [];
        $existingIds = [];
        foreach ($personRows as $row) {
            if (is_array($row) && is_string($row['existingPersonId'] ?? null) && Uuid::isValid($row['existingPersonId'])) {
                $existingIds[] = $row['existingPersonId'];
            }
        }
        $existingPeople = [];
        foreach ($this->people->findRelationshipPeopleByTreeAndIds($draft->tree, array_values(array_unique($existingIds))) as $person) {
            $existingPeople[$person->getId()->toRfc4122()] = $person;
        }
        $newCount = 0;
        foreach ($personRows as $index => $row) {
            $path = 'people[' . $index . ']';
            $row = $this->object($row, ['id', 'existingPersonId', 'resolution', 'fields', 'lifeStatus', 'evidence'], $path);
            $id = $this->reference($row['id'], $path . '.id');
            if (isset($references[$id])) {
                $this->invalid($path . '.id', 'duplicate_reference', 'Each people[].id must be unique; duplicate: ' . $id);
            }
            $references[$id] = true;
            if (!in_array($row['resolution'], ['new', 'existing', 'undecided'], true)
                || !in_array($row['lifeStatus'], ['alive', 'deceased', 'uncertain'], true)) {
                $this->invalid($path, 'person_state_invalid', 'resolution must be new, existing or undecided; lifeStatus must be alive, deceased or uncertain.');
            }
            $existingId = $row['existingPersonId'];
            if (null !== $existingId && (!is_string($existingId) || !Uuid::isValid($existingId))) {
                $this->invalid($path . '.existingPersonId', 'existing_person_id_invalid', 'Expected a UUID observed through MCP, or null for a new or undecided person.');
            }
            $existing = null === $existingId ? null : ($existingPeople[$existingId] ?? null);
            if (null !== $existingId && null === $existing) {
                $this->invalid($path . '.existingPersonId', 'existing_person_unavailable', 'This existing person is not available in the selected tree. Use only people observed through MCP.');
            }
            if (('existing' === $row['resolution']) !== (null !== $existing)) {
                $this->invalid($path . '.resolution', 'resolution_mismatch', 'resolution=existing requires an existingPersonId in this tree; new or undecided requires existingPersonId=null.');
            }
            if (!is_array($row['fields']) || ([] !== $row['fields'] && array_is_list($row['fields']))) {
                $this->invalid($path . '.fields', 'fields_invalid', 'Expected an object containing person fields.');
            }
            $fields = $this->personFields($row['fields'], $path . '.fields');
            if ('uncertain' === $row['lifeStatus']) {
                $row['lifeStatus'] = null !== $existing?->getDeathDate() ? 'deceased' : 'alive';
                $fields['deathDate'] = null === $existing ? null : $this->views->create($existing)['deathDate'];
            }
            if (('alive' === $row['lifeStatus'] && array_key_exists('deathDate', $fields) && null !== $fields['deathDate'])
                || ('deceased' === $row['lifeStatus'] && array_key_exists('deathDate', $fields) && null === $fields['deathDate'])) {
                $this->invalid($path . '.lifeStatus', 'life_status_conflict', 'alive requires deathDate=null; deceased requires a known or unknown death date. New people default to alive unless death is stated; preserve existing death information.');
            }
            if (!array_key_exists('deathDate', $fields)) {
                if ('alive' === $row['lifeStatus']) {
                    $fields['deathDate'] = null;
                } elseif (null === $existing?->getDeathDate()) {
                    $fields['deathDate'] = ['state' => 'unknown'];
                }
            }
            if (null === $existing) {
                ++$newCount;
                $fields += ['birthSurname' => ['state' => 'unknown'], 'givenName' => ['state' => 'unknown'],
                    'patronymic'           => ['state' => 'unknown'], 'gender' => 'unknown',
                    'birthDate'            => ['state' => 'unknown'], 'deathDate' => 'alive' === $row['lifeStatus'] ? null : ['state' => 'unknown']];
                $personData = $this->data->create($fields);
            } else {
                $personData = $this->data->patch($existing, $fields);
            }
            if ('undecided' === $row['resolution'] && [] === $questions) {
                $this->invalid($path . '.resolution', 'question_required', 'Supply the necessary clarification in questions when identity is undecided.');
            }
            $this->evidence($draft, $row['evidence'], $manual, $path . '.evidence');
            $normalized[] = ['id' => $id, 'existingPersonId' => $existingId, 'resolution' => $row['resolution'],
                'fields'          => $fields, 'lifeStatus' => $row['lifeStatus'], 'evidence' => $row['evidence'],
                'before'          => null === $existing ? null : $this->views->create($existing)];
            $changes[] = new PersonChange($id, $existingId, $personData);
        }
        if ($newCount > 10) {
            $this->invalid('people', 'new_people_limit', 'A merge supports at most ten new people. Ask the user to narrow the fragment rather than silently dropping described people.');
        }
        $parents = [];
        foreach ($parentRows as $index => $row) {
            $path = 'parents[' . $index . ']';
            $row = $this->object($row, ['parent', 'child', 'role', 'evidence'], $path);
            $parent = $this->endpoint($row['parent'], $references, $path . '.parent');
            $child = $this->endpoint($row['child'], $references, $path . '.child');
            if (!is_string($row['role']) || null === ($role = ParentRole::tryFrom($row['role']))) {
                $this->invalid($path . '.role', 'parent_role_invalid', 'Expected mother or father, consistent with the source and parent gender.');
            }
            $this->evidence($draft, $row['evidence'], $manual, $path . '.evidence');
            $parents[] = new ParentChange($parent, $child, $role);
        }
        $marriages = [];
        foreach ($marriageRows as $index => $row) {
            $path = 'marriages[' . $index . ']';
            $row = $this->object($row, ['first', 'second', 'marriageDate', 'dissolutionDate', 'newPeriod', 'evidence'], $path);
            $first = $this->endpoint($row['first'], $references, $path . '.first');
            $second = $this->endpoint($row['second'], $references, $path . '.second');
            if (!is_bool($row['newPeriod'])) {
                $this->invalid($path . '.newPeriod', 'new_period_invalid', 'Expected a boolean. A new marriage period requires explicit user choice.');
            }
            $this->evidence($draft, $row['evidence'], $manual, $path . '.evidence');
            $marriages[] = new MarriageChange($first, $second,
                $this->data->dateValue($row['marriageDate'], 'marriageDate'),
                $this->data->optionalDateValue($row['dissolutionDate'], 'dissolutionDate'), $row['newPeriod']);
        }
        $changeSet = new TreeChangeSet($changes, $parents, $marriages);
        $hasChanges = $validateGraph && [] === $questions && $this->projector->project($draft->tree, $changeSet, $this->clock->now())->hasChanges;

        return new ValidatedTreeMergeProposal(
            ['people' => $normalized, 'parents' => $parentRows, 'marriages' => $marriageRows, 'questions' => $proposal['questions']],
            array_values(array_unique($questions)), [] === $questions ? $changeSet : null, $hasChanges,
        );
    }

    /** Remove server-derived presentation fields before a stored proposal is checked again.
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    public function input(array $proposal): array
    {
        if (is_array($proposal['people'] ?? null)) {
            foreach ($proposal['people'] as &$person) {
                if (is_array($person)) {
                    unset($person['before']);
                }
            }
            unset($person);
        }

        return $proposal;
    }

    /** @param list<string> $keys
     * @return array<string, mixed>
     */
    private function object(mixed $value, array $keys, string $path): array
    {
        if (!is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value))) {
            $actual = is_array($value) ? array_keys($value) : [];
            $this->invalid($path, 'shape_invalid', 'Expected exactly these properties: ' . implode(', ', $keys)
                . '. Missing: ' . implode(', ', array_diff($keys, $actual)) . '. Unexpected: ' . implode(', ', array_diff($actual, $keys)) . '.');
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $value[$key];
        }

        return $result;
    }

    /** @return list<mixed> */
    private function items(mixed $value, int $limit, string $path): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            $this->invalid($path, 'list_invalid', 'Expected a list with at most ' . $limit . ' items.');
        }

        return $value;
    }

    /** @param array<mixed> $fields
     * @return array<string, mixed>
     */
    private function personFields(array $fields, string $path): array
    {
        $allowed = ['birthSurname', 'givenName', 'patronymic', 'gender', 'birthDate', 'deathDate'];
        $result = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $this->invalid($path, 'field_unknown', 'Allowed person fields: ' . implode(', ', $allowed) . '.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function reference(mixed $value, string $path): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) {
            $this->invalid($path, 'reference_invalid', 'Expected an ASCII temporary id of 1 to 64 letters, digits, underscores or hyphens.');
        }

        return $value;
    }

    /** @param array<string, true> $references */
    private function endpoint(mixed $value, array $references, string $path): string
    {
        $id = $this->reference($value, $path);
        if (!isset($references[$id])) {
            $this->invalid($path, 'person_reference_unknown', 'Received "' . $id . '". Relationship endpoints must exactly match people[].id, not names or database UUIDs. Available ids: '
                . implode(', ', array_keys($references)) . '. Preserve the supported relationship and correct its endpoint.');
        }

        return $id;
    }

    private function evidence(TreeMergeDraft $draft, mixed $value, bool $manual, string $path): void
    {
        $items = $this->items($value, 10, $path);
        if (!$manual && [] === $items) {
            $this->invalid($path, 'evidence_required', 'Provide at least one exact supporting quote and its user messageId.');
        }
        foreach ($items as $index => $item) {
            $itemPath = $path . '[' . $index . ']';
            $item = $this->object($item, ['messageId', 'quote'], $itemPath);
            if (!is_string($item['messageId']) || !is_string($item['quote']) || '' === trim($item['quote']) || mb_strlen($item['quote']) > self::MAX_QUOTE_LENGTH) {
                $this->invalid($itemPath, 'evidence_invalid', 'messageId and quote must be nonempty strings; quote must not exceed 300 characters.');
            }
            $found = false;
            if ($manual && null !== $draft->proposal) {
                foreach (['people', 'parents', 'marriages'] as $group) {
                    foreach (is_array($draft->proposal[$group] ?? null) ? $draft->proposal[$group] : [] as $row) {
                        if (is_array($row) && is_array($row['evidence'] ?? null) && in_array($item, $row['evidence'], true)) {
                            $found = true;
                        }
                    }
                }
            }
            foreach ($this->chat->messages($draft) as $message) {
                if ($message['id'] === $item['messageId'] && 'user' === $message['role']
                    && str_contains($message['content'], $item['quote'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->invalid($itemPath, 'evidence_not_found', 'quote must be an exact substring of the user message identified by messageId. Do not paraphrase or invent quotes.');
            }
        }
    }

    private function invalid(string $field, string $code, string $message): never
    {
        throw new ApiException(422, 'TREE_MERGE_PROPOSAL_INVALID', 'tree_merge_proposal_invalid', [$field => [$code]], ['violations' => [['path' => $field, 'code' => $code, 'message' => $message]]]);
    }
}
