<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;

final readonly class MergeAiRepair
{
    public function __construct(public MergeAiResult $previous, public ApiException $error, public bool $questionsOnly = false)
    {
    }

    public function instruction(): string
    {
        return $this->questionsOnly
            ? 'Automatic proposal repairs are exhausted. Do NOT produce another proposal. This call uses a question-only schema: return ONLY questions[] containing one to three questions. Do not include action, proposal or reply. '
                . 'Use the specific validation reasons and conversation below. Briefly explain the relevant obstacle in each question and name the people involved. '
                . 'Ask only for decisions or facts necessary to resolve it, never optional profile details or internal identifiers. '
                . 'For more than ten new people, ask which branch or people to prepare first. Do not blame the user for malformed model JSON or invented evidence. '
                . 'Do not silently omit requested relatives. Your questions become assistant messages; the next reply may simply be yes or a name.'
            : 'Correct the COMPLETE proposal using the server validation feedback. Preserve supported facts and reviewed corrections. '
                . 'If facts or decisions are missing, return action=clarification with proposal=null and concrete questions in questions[].';
    }

    public function fallbackQuestion(bool $russian): string
    {
        $codes = array_merge(...array_values($this->error->getFields()));
        if (in_array('new_people_limit', $codes, true)) {
            return $russian ? 'За один раз можно добавить до десяти новых персон. Кого из описанных родственников добавим сначала?'
                : 'Up to ten new people can be added at a time. Which of the described relatives should we add first?';
        }
        if (array_any(array_keys($this->error->getFields()), static fn ($path) => str_contains(strtolower($path), 'date')) || str_contains($this->error->getErrorCode(), 'CHRONOLOGY')) {
            return $russian ? 'Не удалось согласовать указанные даты. Какие даты рождения или смерти в последнем описании нужно исправить?'
                : 'I could not reconcile the supplied dates. Which birth or death dates in the last description should be corrected?';
        }
        if (in_array('person_reference_unknown', $codes, true) || str_contains($this->error->getErrorCode(), 'PARENT') || str_contains($this->error->getErrorCode(), 'CONNECT')) {
            return $russian ? 'Не удалось связать предложенную ветку с указанными людьми. Кто из описанных родственников является родителем или супругом человека в дереве?'
                : 'I could not connect the proposed branch to the named people. Which described relative is a parent or spouse of someone in the tree?';
        }

        return $russian ? 'Не удалось подтвердить сведения в предложении по исходному описанию. С какой персоны и её ближайших родственников начнём?'
            : 'I could not verify the proposal against the original description. Which person and their closest relatives should we start with?';
    }

    /** @return array<string, mixed> */
    public function feedback(): array
    {
        return [
            'code'    => $this->error->getErrorCode(),
            'fields'  => $this->error->getFields(),
            'details' => $this->error->getDetails(),
        ];
    }
}
