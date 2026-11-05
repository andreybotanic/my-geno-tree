<?php

namespace App\Application\Chat\Voice;

use App\Api\ApiException;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class ChatTranscriptionService
{
    public function __construct(private AudioNormalizer $normalizer, private TranscriptionProviderInterface $provider,
        private LoggerInterface $logger,
        #[Target('chat_voice')] private RateLimiterFactoryInterface $limiter)
    {
    }

    /** @return array{text: string, durationSeconds: float} */
    public function transcribe(User $user, UploadedFile $file): array
    {
        try {
            if (!$this->limiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
                throw new ApiException(429, 'CHAT_VOICE_RATE_LIMIT');
            }
            $recording = $this->normalizer->normalize($file);
            try {
                $text = $this->provider->transcribe($recording->wav, $user->getPreferredLanguage());
                if ('' === trim($text) || mb_strlen($text) > 10000) {
                    throw new \RuntimeException('TRANSCRIPTION_INVALID');
                }
            } catch (\Throwable $error) {
                $this->logger->warning('Chat transcription failed: ' . $error::class);
                throw new ApiException(503, 'CHAT_TRANSCRIPTION_UNAVAILABLE', previous: $error);
            }

            return ['text' => $text, 'durationSeconds' => $recording->durationSeconds];
        } finally {
            // PHP also removes request uploads; explicitly release the recording before returning.
            if (is_file($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }
}
