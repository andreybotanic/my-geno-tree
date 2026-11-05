<?php

namespace App\Application\Chat\Voice;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(TranscriptionProviderInterface::class, when: ['dev', 'prod'])]
final readonly class WhisperTranscriptionProvider implements TranscriptionProviderInterface
{
    public function __construct(private HttpClientInterface $http,
        #[Autowire(env: 'WHISPER_URL')] private string $url,
        #[Autowire(env: 'WHISPER_MODEL')] private string $model)
    {
    }

    public function transcribe(string $wav, string $language): string
    {
        $body = new FormDataPart(['file' => new DataPart($wav, 'recording.wav', 'audio/wav'),
            'model'                      => $this->model, 'language' => $language, 'response_format' => 'json']);
        $response = $this->http->request('POST', rtrim($this->url, '/') . '/v1/audio/transcriptions', [
            'headers' => $body->getPreparedHeaders()->toArray(), 'body' => $body->bodyToIterable(),
            'timeout' => 180, 'max_duration' => 180,
        ])->toArray();
        if (!is_string($response['text'] ?? null)) {
            throw new \RuntimeException('TRANSCRIPTION_INVALID');
        }

        return trim($response['text']);
    }
}
