<?php

namespace App\Api;

use App\Application\TreeMerge\Ai\MergeTokenBudget;
use App\Application\TreeMerge\MergeActivity;
use App\Application\TreeMerge\TreeMergeChat;
use App\Entity\TreeMergeDraft;

final class TreeMergeDraftViewFactory
{
    public function __construct(private readonly MergeActivity $activity, private readonly MergeTokenBudget $budget, private readonly TreeMergeChat $chat)
    {
    }

    /** @return array<string, mixed> */
    public function create(TreeMergeDraft $draft): array
    {
        $activity = $this->activity->read($draft);

        return [
            'id'                => $draft->id->toRfc4122(),
            'treeId'            => $draft->tree->getId()->toRfc4122(),
            'referencePersonId' => $draft->referencePerson?->getId()->toRfc4122(),
            'version'           => $draft->version,
            'chatState'         => $draft->chatState,
            'attemptId'         => $draft->attemptId,
            'attemptStartedAt'  => $draft->attemptStartedAt?->format(\DateTimeInterface::ATOM),
            'proposalVersion'   => $draft->proposalVersion,
            'proposal'          => $draft->proposal,
            'questions'         => $draft->proposal['questions'] ?? [],
            'result'            => $draft->result,
            'state'             => $draft->state,
            'treeEtag'          => $draft->treeEtag,
            'messages'          => $this->chat->messages($draft),
            'progress'          => $activity['progress'],
            'tokenUsage'        => $activity['usage'],
            'tokenBudget'       => $this->budget->remaining($draft->owner),
            'createdAt'         => $draft->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt'         => $draft->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
