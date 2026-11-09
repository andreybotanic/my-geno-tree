<?php

namespace App\Api;

use App\Entity\TreeDocument;

final class DocumentViewFactory
{
    /** @return array<string, mixed> */
    public function create(TreeDocument $document): array
    {
        $treeId = $document->getTree()?->getId()->toRfc4122();

        return [
            'id'                => $document->getId()->toRfc4122(),
            'treeId'            => $treeId,
            'personId'          => $document->getPerson()?->getId()->toRfc4122(),
            'originalName'      => $document->getOriginalName(),
            'mimeType'          => $document->getMimeType(),
            'originalSizeBytes' => $document->getOriginalSizeBytes(),
            'status'            => $document->getStatus(),
            'errorCode'         => $document->getErrorCode(),
            'createdAt'         => $document->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'         => $document->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'downloadUrl'       => '/trees/' . $treeId . '/documents/' . $document->getId() . '/download',
        ];
    }
}
