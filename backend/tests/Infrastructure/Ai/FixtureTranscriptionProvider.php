<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\TreeMerge\Voice\TranscriptionProviderInterface;

final class FixtureTranscriptionProvider implements TranscriptionProviderInterface
{
    public static int $calls = 0;
    public static string|\Throwable $response = 'Мама Ирины — Вера.';
    public static ?\Closure $handler = null;

    public function transcribe(string $wav, string $language): string
    {
        ++self::$calls;
        if (null !== self::$handler) {
            return (self::$handler)($wav, $language);
        }

        return self::$response instanceof \Throwable ? throw self::$response : self::$response;
    }
}
