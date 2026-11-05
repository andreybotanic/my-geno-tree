<?php

namespace App\Application\Chat\Voice;

interface TranscriptionProviderInterface
{
    public function transcribe(string $wav, string $language): string;
}
