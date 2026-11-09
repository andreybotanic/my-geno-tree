<?php

namespace App\Message\Document;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('documents')]
final readonly class IndexDocumentMessage
{
    public function __construct(public string $documentId, public int $generation)
    {
    }
}
