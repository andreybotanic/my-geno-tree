<?php

namespace App\Application\Media;

final readonly class CropArea
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }
}
