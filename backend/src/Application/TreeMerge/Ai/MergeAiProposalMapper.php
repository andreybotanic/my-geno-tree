<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiProposal;
use App\Entity\TreeMergeDraft;

/** @phpstan-import-type ContextPerson from MergeAiResult */
final class MergeAiProposalMapper
{
    public function __construct(private readonly MergeDateParser $dates = new MergeDateParser())
    {
    }

    /** @param array<string, ContextPerson> $context
     * @return array<string, mixed>
     */
    public function map(TreeMergeDraft $draft, MergeAiProposal $input, array $context): array
    {
        /** @var array{people: list<array{id: string, existingPersonId: ?string, resolution: string, lifeStatus: string, fields: array<string, array<string, mixed>|string|null>, evidence: list<array{messageId: string, quote: string}>}>, parents: list<array<string, mixed>>, marriages: list<array{first: string, second: string, marriageDate: string, dissolutionDate: ?string, newPeriod: bool, evidence: list<array{messageId: string, quote: string}>}>, questions: list<string>} $proposal */
        $proposal = json_decode(json_encode($input, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $russian = 'ru' === $draft->owner->getPreferredLanguage();
        foreach ($proposal['people'] as &$person) {
            $existingId = $person['existingPersonId'];
            if (null !== $existingId && !isset($context[$existingId])) {
                throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON');
            }
            $fields = array_filter($person['fields'], static fn ($value) => null !== $value);
            $unknownNames = $fields['unknownNames'] ?? [];
            unset($fields['unknownNames']);
            foreach ($fields as $field => &$value) {
                if (is_string($value) && in_array($field, ['givenName', 'birthSurname', 'patronymic'], true)) {
                    $value = ['state' => 'known', 'value' => $value];
                } elseif (is_string($value) && in_array($field, ['birthDate', 'deathDate'], true)) {
                    $value = $this->dates->parse($value);
                }
            }
            unset($value);
            if (!is_array($unknownNames)) {
                throw new ApiException(422, 'TREE_MERGE_AI_INVALID');
            }
            foreach ($unknownNames as $field) {
                if (!is_string($field) || !in_array($field, ['givenName', 'birthSurname', 'patronymic'], true) || isset($fields[$field])) {
                    throw new ApiException(422, 'TREE_MERGE_AI_INVALID');
                }
                $fields[$field] = ['state' => 'unknown'];
            }
            $quotes = implode(' ', array_column($person['evidence'], 'quote'));
            foreach (['givenName', 'birthSurname', 'patronymic'] as $field) {
                $value = is_array($fields[$field] ?? null) ? $fields[$field]['value'] ?? null : null;
                if (is_string($value) && ($context[$existingId ?? ''][$field]['value'] ?? null) !== $value
                    && !str_contains(mb_strtolower($quotes), mb_strtolower(mb_substr($value, 0, max(2, mb_strlen($value) - 2))))) {
                    $proposal['questions'][] = $russian ? 'Подтвердите написание имени: ' . $value : 'Confirm the spelling of this name: ' . $value;
                }
            }
            foreach (['birthDate', 'deathDate'] as $field) {
                $year = is_array($fields[$field] ?? null) ? $fields[$field]['year'] ?? null : null;
                if (is_int($year) && ($context[$existingId ?? ''][$field]['year'] ?? null) !== $year && !str_contains($quotes, (string)$year)) {
                    $proposal['questions'][] = $russian ? 'Уточните год: ' . $year : 'Clarify the year: ' . $year;
                }
            }
            $knownStatus = null;
            if (isset($context[$existingId ?? ''])) {
                $knownStatus = null === $context[$existingId ?? '']['deathDate'] ? 'alive' : 'deceased';
            }
            $statusEvidence = match ($person['lifeStatus']) {
                'alive'    => '/\b(?:жив[аы]?|alive|living)\b/iu',
                'deceased' => '/(?:умер|скончал|погиб|deceased|died|dead)/iu',
                default    => '/(?!) /',
            };
            if ($knownStatus !== $person['lifeStatus'] && 1 !== preg_match($statusEvidence, $quotes)) {
                $person['lifeStatus'] = 'uncertain';
            }
            if ('alive' === $person['lifeStatus']) {
                $fields['deathDate'] = null;
            }
            // An implicit match is permitted only for the explicitly selected narrative reference.
            $explicit = $draft->referencePerson?->getId()->toRfc4122() === $existingId;
            foreach (array_slice($draft->corrections, -1) as $correction) {
                /** @var array{people: list<array{existingPersonId: ?string}>} $reviewedProposal */
                $reviewedProposal = $correction['proposal'];
                foreach ($reviewedProposal['people'] as $reviewed) {
                    $explicit = $explicit || ($reviewed['existingPersonId'] ?? null) === $existingId;
                }
            }
            if (null !== $existingId && !$explicit) {
                $person['resolution'] = 'undecided';
                $person['existingPersonId'] = null;
                $fields = array_replace($context[$existingId], $fields);
                unset($fields['id']);
            }
            $person['fields'] = $fields ?: ['givenName' => ['state' => 'unknown']];
        }
        unset($person);
        foreach ($proposal['parents'] as $link) {
            $evidence = is_array($link['evidence'] ?? null) ? $link['evidence'] : [];
            if (1 !== preg_match('/мам|мат[ьиер]|от[ец]|пап|сын|доч|дет|реб[её]н|parent|mother|father|son|daughter|child/iu', implode(' ', array_column($evidence, 'quote')))) {
                $proposal['questions'][] = $russian ? 'Уточните общего родителя: брат или сестра сами по себе не устанавливают родительскую связь.'
                    : 'Clarify the common parent: siblings alone do not establish a parent relationship.';
            }
        }
        foreach ($proposal['marriages'] as &$marriage) {
            $marriage['marriageDate'] = $this->dates->parse($marriage['marriageDate']);
            if (null !== $marriage['dissolutionDate']) {
                $marriage['dissolutionDate'] = $this->dates->parse($marriage['dissolutionDate']);
            }
            // The preview, not the model, chooses a separate marriage period.
            $marriage['newPeriod'] = false;
            foreach (array_slice($draft->corrections, -1) as $correction) {
                /** @var array{marriages?: list<array<string, mixed>>} $reviewedProposal */
                $reviewedProposal = $correction['proposal'];
                foreach ($reviewedProposal['marriages'] ?? [] as $reviewed) {
                    if (($reviewed['first'] ?? null) === $marriage['first'] && ($reviewed['second'] ?? null) === $marriage['second']
                        && ($reviewed['marriageDate'] ?? null) === $marriage['marriageDate']) {
                        $marriage['newPeriod'] = true === ($reviewed['newPeriod'] ?? false);
                    }
                }
            }
        }

        return $proposal;
    }
}
