<?php

namespace App\Application\Media;

use App\Entity\MediaVariantType;

final readonly class ProcessedVariant
{
    public function __construct(
        public MediaVariantType $type,
        public string $contents,
        public int $width,
        public int $height,
    ) {
    }
}
