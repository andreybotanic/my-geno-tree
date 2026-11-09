<?php

namespace App\Application\Document;

final readonly class RemoveDocument
{
    public function __construct(public string $documentId)
    {
    }
}
