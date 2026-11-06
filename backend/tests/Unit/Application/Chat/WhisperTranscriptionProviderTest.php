<?php

namespace App\Tests\Unit\Application\Chat;

use App\Application\Chat\Voice\WhisperTranscriptionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WhisperTranscriptionProviderTest extends TestCase
{
    public function testSendsNormalizedAudioWithLanguageAndModel(): void
    {
        $http = new MockHttpClient(
            function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('http://whisper:8000/v1/audio/transcriptions', $url);
                $body = '';
                while ('' !== $chunk = $options['body'](16384)) {
                    $body .= $chunk;
                }
                self::assertStringContainsString('audio/wav', $body);
                self::assertStringContainsString('RIFFtest', $body);
                self::assertStringContainsString('name="language"', $body);
                self::assertStringContainsString('ru', $body);
                self::assertStringContainsString('small', $body);

                return new MockResponse('{"text":"  Мама — Вера.  "}');
            }
        );
        self::assertSame('Мама — Вера.', new WhisperTranscriptionProvider($http, 'http://whisper:8000/', 'small')->transcribe('RIFFtest', 'ru'));
    }

    public function testRejectsMalformedProviderResponse(): void
    {
        $this->expectException(\RuntimeException::class);
        new WhisperTranscriptionProvider(new MockHttpClient(new MockResponse('{"text":false}')), 'http://whisper', 'small')->transcribe('RIFF', 'en');
    }
}
