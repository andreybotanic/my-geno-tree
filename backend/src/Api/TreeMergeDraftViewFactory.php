<?php

namespace App\Api;

use App\Entity\TreeMergeDraft;

final class TreeMergeDraftViewFactory
{
    /** @return array<string, mixed> */
    public function create(TreeMergeDraft $draft): array
    {
        return [
            'id'                => $draft->id->toRfc4122(),
            'treeId'            => $draft->tree->getId()->toRfc4122(),
            'referencePersonId' => $draft->referencePerson?->getId()->toRfc4122(),
            'version'           => $draft->version,
            'sourceVersion'     => $draft->sourceVersion,
            'proposalVersion'   => $draft->proposalVersion,
            'proposal'          => $draft->proposal,
            'questions'         => $draft->questions,
            'result'            => $draft->result,
            'state'             => $draft->state,
            'treeEtag'          => $draft->treeEtag,
            'messages'          => $draft->messages,
            'createdAt'         => $draft->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt'         => $draft->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
