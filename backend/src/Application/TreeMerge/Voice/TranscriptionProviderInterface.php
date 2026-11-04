<?php

namespace App\Application\TreeMerge\Voice;

interface TranscriptionProviderInterface
{
    public function transcribe(string $wav, string $language): string;
}
