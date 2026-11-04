<?php

namespace App\Application\TreeMerge\Voice;

final readonly class NormalizedRecording
{
    public function __construct(public string $wav, public string $mimeType, public float $durationSeconds)
    {
    }
}
