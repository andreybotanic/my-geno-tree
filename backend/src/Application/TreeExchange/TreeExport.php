<?php

namespace App\Application\TreeExchange;

final readonly class TreeExport
{
    public function __construct(
        public string $contents,
        public string $fileName,
    ) {
    }
}
