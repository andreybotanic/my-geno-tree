<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Api\PersonViewFactory;
use App\Application\Person\PersonDataPolicy;
use App\Application\Person\PersonSearchService;
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
    public function __construct(
        private PersonDataPolicy $data,
        private PersonRepository $people,
        private PersonSearchService $search,
        private PersonViewFactory $views,
        private TreeChangeSetProjector $projector,
        private ClockInterface $clock,
    ) {
    }

    public function validate(TreeMergeDraft $draft, mixed $value, bool $manual = false): ValidatedTreeMergeProposal
    {
        $proposal = $this->object($value, ['people', 'parents', 'marriages', 'questions']);
        $personRows = $this->items($proposal['people'], 20);
        $parentRows = $this->items($proposal['parents'], 40);
        $marriageRows = $this->items($proposal['marriages'], 20);
        $questions = $this->items($proposal['questions'], 30);
        foreach ($questions as $question) {
            if (!is_string($question) || '' === trim($question) || mb_strlen($question) > 1000) {
                $this->invalid('questions');
            }
        }
        $normalized = $changes = $references = [];
        $newCount = 0;
        foreach ($personRows as $row) {
            $row = $this->object($row, ['id', 'existingPersonId', 'resolution', 'fields', 'lifeStatus', 'evidence']);
            $id = $this->reference($row['id']);
            if (isset($references[$id])) {
                $this->invalid('duplicate_reference');
            }
            $references[$id] = true;
            if (!in_array($row['resolution'], ['new', 'existing', 'undecided'], true)
                || !in_array($row['lifeStatus'], ['alive', 'deceased', 'uncertain'], true)) {
                $this->invalid('resolution');
            }
            $existingId = $row['existingPersonId'];
            if (null !== $existingId && (!is_string($existingId) || !Uuid::isValid($existingId))) {
                $this->invalid('existingPersonId');
            }
            $existing = null === $existingId ? null : $this->people->findInTree($draft->tree, $existingId);
            if (null !== $existingId && null === $existing) {
                $this->invalid('existingPersonId');
            }
            if (('existing' === $row['resolution']) !== (null !== $existing)) {
                $this->invalid('resolution');
            }
            if (!is_array($row['fields']) || array_is_list($row['fields'])) {
                $this->invalid('fields');
            }
            $fields = $this->personFields($row['fields']);
            if ('uncertain' === $row['lifeStatus']) {
                $questions[] = 'life_status:' . $id;
            } elseif (('alive' === $row['lifeStatus'] && array_key_exists('deathDate', $fields) && null !== $fields['deathDate'])
                || ('deceased' === $row['lifeStatus'] && array_key_exists('deathDate', $fields) && null === $fields['deathDate'])) {
                $this->invalid('lifeStatus');
            }
            if (!array_key_exists('deathDate', $fields) && 'uncertain' !== $row['lifeStatus']) {
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
            $name = $personData->givenName->getValue();
            $candidates = null !== $name && mb_strlen($name) >= 2 ? $this->search->search($draft->tree, $name) : [];
            if (!$manual && [] !== $candidates && 'new' === $row['resolution']) {
                $explicitNew = false;
                foreach ($draft->corrections as $correction) {
                    /** @var array{people: list<array{id: string, resolution: string}>} $reviewed */
                    $reviewed = $correction['proposal'];
                    foreach ($reviewed['people'] as $choice) {
                        $explicitNew = $explicitNew || ($choice['id'] === $id && 'new' === $choice['resolution']);
                    }
                }
                if (!$explicitNew) {
                    $row['resolution'] = 'undecided';
                }
            }
            if ('undecided' === $row['resolution']) {
                $questions[] = 'person_match:' . $id;
            }
            $this->evidence($draft, $row['evidence'], $manual);
            $normalized[] = ['id' => $id, 'existingPersonId' => $existingId, 'resolution' => $row['resolution'],
                'fields'          => $fields, 'lifeStatus' => $row['lifeStatus'], 'evidence' => $row['evidence'],
                'candidates'      => $candidates, 'before' => null === $existing ? null : $this->views->create($existing)];
            $changes[] = new PersonChange($id, $existingId, $personData);
        }
        if ($newCount > 5) {
            $this->invalid('new_people_limit');
        }
        $parents = [];
        foreach ($parentRows as $row) {
            $row = $this->object($row, ['parent', 'child', 'role', 'evidence']);
            $parent = $this->reference($row['parent']);
            $child = $this->reference($row['child']);
            if (!isset($references[$parent], $references[$child]) || !is_string($row['role'])) {
                $this->invalid('parents');
            }
            $role = ParentRole::tryFrom($row['role']) ?? $this->invalid('role');
            $this->evidence($draft, $row['evidence'], $manual);
            $parents[] = new ParentChange($parent, $child, $role);
        }
        $marriages = [];
        foreach ($marriageRows as $row) {
            $row = $this->object($row, ['first', 'second', 'marriageDate', 'dissolutionDate', 'newPeriod', 'evidence']);
            $first = $this->reference($row['first']);
            $second = $this->reference($row['second']);
            if (!isset($references[$first], $references[$second]) || !is_bool($row['newPeriod'])) {
                $this->invalid('marriages');
            }
            $this->evidence($draft, $row['evidence'], $manual);
            $marriages[] = new MarriageChange($first, $second,
                $this->data->dateValue($row['marriageDate'], 'marriageDate'),
                $this->data->optionalDateValue($row['dissolutionDate'], 'dissolutionDate'), $row['newPeriod']);
        }
        $changeSet = new TreeChangeSet($changes, $parents, $marriages);
        $hasChanges = [] === $questions && $this->projector->project($draft->tree, $changeSet, $this->clock->now())->hasChanges;

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
                    unset($person['before'], $person['candidates']);
                }
            }
            unset($person);
        }

        return $proposal;
    }

    /** @param list<string> $keys
     * @return array<string, mixed>
     */
    private function object(mixed $value, array $keys): array
    {
        if (!is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value))) {
            $this->invalid('shape');
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $value[$key];
        }

        return $result;
    }

    /** @return list<mixed> */
    private function items(mixed $value, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            $this->invalid('list');
        }

        return $value;
    }

    /** @param array<mixed> $fields
     * @return array<string, mixed>
     */
    private function personFields(array $fields): array
    {
        $allowed = ['birthSurname', 'givenName', 'patronymic', 'gender', 'birthDate', 'deathDate'];
        $result = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $this->invalid('fields');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function reference(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) {
            $this->invalid('reference');
        }

        return $value;
    }

    private function evidence(TreeMergeDraft $draft, mixed $value, bool $manual): void
    {
        $items = $this->items($value, 10);
        if (!$manual && [] === $items) {
            $this->invalid('evidence_required');
        }
        foreach ($items as $item) {
            $item = $this->object($item, ['messageId', 'quote']);
            if (!is_string($item['messageId']) || !is_string($item['quote']) || '' === trim($item['quote'])) {
                $this->invalid('evidence');
            }
            $found = false;
            foreach ($draft->messages as $message) {
                if ($message['id'] === $item['messageId'] && 'user' === $message['role']
                    && str_contains($message['content'], $item['quote'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->invalid('evidence');
            }
        }
    }

    private function invalid(string $field): never
    {
        throw new ApiException(422, 'TREE_MERGE_PROPOSAL_INVALID', 'tree_merge_proposal_invalid', [$field => ['invalid']]);
    }
}
