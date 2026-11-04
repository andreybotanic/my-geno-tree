<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiPerson;
use App\Application\TreeMerge\Ai\Dto\MergeAiProposal;
use App\Entity\Value\ParentRole;

/** @phpstan-import-type ContextPerson from MergeAiResult */
final class MergeInferenceResolver
{
    /** @param array<string, ContextPerson> $context
     * @return list<string>
     */
    public function resolve(MergeAiPerson $person, MergeAiProposal $proposal, array $context): array
    {
        $accepted = [];
        foreach ($person->inferences as $inference) {
            $field = $inference->field;
            $source = array_find($proposal->people, static fn ($candidate) => $candidate->id === $inference->sourcePersonId);
            if ('new' !== $person->resolution || null !== $person->existingPersonId || null === $source || $source === $person
                || !in_array($field, ['birthDate', 'birthSurname', 'patronymic'], true) || in_array($field, $accepted, true)
                || '' === trim($inference->explanation) || mb_strlen($inference->explanation) > 500
                || in_array($field, $person->fields->unknownNames, true)
                || array_any($source->inferences, static fn ($item) => $item->field === $field)
                || (null !== $source->existingPersonId && !isset($context[$source->existingPersonId]))) {
                $this->invalid($person->id, $field);
            }
            $known = $context[$source->existingPersonId ?? ''] ?? null;
            if ('birthDate' === $field) {
                $date = null !== $known ? $known['birthDate'] : (new MergeDateParser())->parse($source->fields->birthDate ?? 'unknown');
                $offset = $inference->yearOffset;
                $quotes = implode(' ', array_map(static fn ($evidence) => $evidence->quote, $person->evidence));
                $younger = 1 === preg_match('/младше|моложе|younger/iu', $quotes);
                $older = 1 === preg_match('/старше|older/iu', $quotes);
                if (!is_int($date['year'] ?? null) || !in_array($date['kind'] ?? '', ['exact', 'approximate'], true)
                    || null === $offset || abs($offset) > 150 || (!$younger && !$older)
                    || ($younger && !$older && $offset <= 0) || ($older && !$younger && $offset >= 0)
                    || 'unknown' === $person->fields->birthDate) {
                    $this->invalid($person->id, $field);
                }
                // Relative age never establishes an exact birthday, even with a day-precise source.
                $person->fields->birthDate = '~' . ($date['year'] + $offset);
            } else {
                $value = $person->fields->{$field};
                $sourceValue = null !== $known ? ($known[$field]['value'] ?? null) : $source->fields->{$field};
                $gender = $source->fields->gender ?? $known['gender'] ?? 'unknown';
                $direct = array_find($proposal->parents, static fn ($link) => $link->parent === $source->id && $link->child === $person->id);
                $shared = array_any($proposal->parents, static fn ($link) => $link->child === $person->id
                    && ('birthSurname' === $field || ParentRole::FATHER === $link->role)
                    && array_any($proposal->parents, static fn ($other) => $other->child === $source->id && $other->parent === $link->parent && $other->role === $link->role));
                $sameGender = 'unknown' !== $gender && $gender === $person->fields->gender;
                $supported = is_string($value) && '' !== trim($value) && $value === $sourceValue
                    && (('birthSurname' === $field && null !== $direct) || ($shared && $sameGender));
                if ('patronymic' === $field && ParentRole::FATHER === $direct?->role && is_string($value)) {
                    $father = null !== $known ? ($known['givenName']['value'] ?? null) : $source->fields->givenName;
                    // Regular Russian patronymics; irregular forms need a known sibling or clarification.
                    $supported = is_string($father) && 1 === preg_match('/[бвгджзклмнпрстфхцчшщ]$/iu', $father)
                        && mb_strtolower($value) === mb_strtolower($father . ('female' === $person->fields->gender ? 'овна' : 'ович'));
                }
                if (!$supported || null !== $inference->yearOffset) {
                    $this->invalid($person->id, $field);
                }
            }
            $accepted[] = $field;
        }

        return $accepted;
    }

    private function invalid(string $personId, string $field): never
    {
        throw new ApiException(422, 'TREE_MERGE_INFERENCE_INVALID', fields: ['inferences' => ['unsupported_inference']], details: ['violations' => [['path' => 'inferences', 'message' => 'Unsupported inference for ' . $personId . '.' . $field . '. Use an observed/explicit source and an established family link for names. Relative ages require a known birth year, a signed yearOffset, and the original age comparison in evidence. Preserve explicit unknowns and existing values. Remove unsupported deductions, not the person.']]]);
    }
}
