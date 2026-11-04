<?php

namespace App\Application\TreeMerge\Ai;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;
use App\Entity\TreeMergeDraft;

final class MergeReplySummary
{
    /** @param array<string, mixed>|null $proposal */
    public function describe(TreeMergeDraft $draft, MergeAiResponse $response, ?array $proposal): string
    {
        $russian = 'ru' === $draft->owner->getPreferredLanguage();
        /** @var list<array{resolution: string}> $people */
        $people = $proposal['people'] ?? [];
        $new = count(array_filter($people, static fn ($person) => 'new' === $person['resolution']));
        $existing = count(array_filter($people, static fn ($person) => 'existing' === $person['resolution']));
        $lines = [$russian ? 'Новых персон к добавлению: ' . $new . '.' : 'New people to add: ' . $new . '.'];
        if ($existing > 0) {
            $lines[] = $russian ? 'Уже есть в дереве: ' . $existing . '; повторно не добавляются.'
                : 'Already in the tree: ' . $existing . '; they will not be added again.';
        }
        if (count($response->omittedPeople) > 20) {
            throw new ApiException(422, 'TREE_MERGE_OMISSION_INVALID');
        }
        foreach ($response->omittedPeople as $omission) {
            if ('' === trim($omission->people) || mb_strlen($omission->people) > 200 || '' === trim($omission->reason)
                || mb_strlen($omission->reason) > 500 || !isset($omission->evidence) || '' === trim($omission->evidence->quote)
                || !array_any($draft->messages, static fn ($message) => 'user' === $message['role'] && $message['id'] === $omission->evidence->messageId
                    && str_contains($message['content'], $omission->evidence->quote))) {
                throw new ApiException(422, 'TREE_MERGE_OMISSION_INVALID', fields: ['omittedPeople' => ['explanation_and_user_evidence_required']]);
            }
            $lines[] = ($russian ? 'Не включены: ' : 'Not included: ') . $omission->people . ' — ' . $omission->reason;
        }
        if (null !== $proposal && null !== $response->proposal) {
            foreach ($response->proposal->people as $person) {
                foreach ($person->inferences as $inference) {
                    $lines[] = ($russian ? 'Предположение: ' : 'Inference: ') . $inference->explanation;
                }
            }
        }

        return implode("\n", $lines);
    }
}
