<?php

namespace App\Api;

use App\Application\TreeMerge\Ai\MergeTokenBudget;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\TreeMergeDraft;

final class TreeMergeDraftViewFactory
{
    public function __construct(private readonly MergeActivity $activity, private readonly MergeTokenBudget $budget)
    {
    }

    /** @return array<string, mixed> */
    public function create(TreeMergeDraft $draft): array
    {
        return [
            'id'                   => $draft->id->toRfc4122(),
            'treeId'               => $draft->tree->getId()->toRfc4122(),
            'referencePersonId'    => $draft->referencePerson?->getId()->toRfc4122(),
            'version'              => $draft->version,
            'sourceVersion'        => $draft->sourceVersion,
            'attemptId'            => $draft->attemptId,
            'attemptSourceVersion' => $draft->attemptSourceVersion,
            'attemptStartedAt'     => $draft->attemptStartedAt?->format(\DateTimeInterface::ATOM),
            'proposalVersion'      => $draft->proposalVersion,
            'proposal'             => $draft->proposal,
            'questions'            => $draft->questions,
            'result'               => $draft->result,
            'state'                => $draft->state,
            'treeEtag'             => $draft->treeEtag,
            'messages'             => $draft->messages,
            'progress'             => $this->activity->read($draft)['progress'],
            'tokenUsage'           => $this->activity->read($draft)['usage'],
            'tokenBudget'          => $this->budget->remaining($draft->owner),
            'createdAt'            => $draft->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt'            => $draft->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
