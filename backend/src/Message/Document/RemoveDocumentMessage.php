<?php

namespace App\Message\Document;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('documents')]
final readonly class RemoveDocumentMessage
{
    public function __construct(public string $documentId)
    {
    }
}
