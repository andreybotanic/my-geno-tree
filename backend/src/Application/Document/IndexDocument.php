<?php

namespace App\Application\Document;

final readonly class IndexDocument
{
    public function __construct(public string $documentId, public int $generation)
    {
    }
}
