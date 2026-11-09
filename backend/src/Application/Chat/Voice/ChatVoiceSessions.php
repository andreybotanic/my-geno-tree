<?php

namespace App\Application\Chat\Voice;

use App\Api\ApiException;
use App\Application\Chat\Moderation\ChatModeration;
use App\Entity\AuthSession;
use App\Repository\AuthSessionRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/** Short-lived recording grants, bound to a revocable login and kept only in RAM-backed cache. */
final readonly class ChatVoiceSessions
{
    private const int SESSION_TTL = 420;

    public function __construct(
        #[Target('cache.chat')]
        private CacheItemPoolInterface $cache,
        private AuthSessionRepository $sessions,
        private ChatModeration $moderation,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
    }

    /** @return array{id: string, url: string, credential: string, expiresIn: int,
     * maxDurationSeconds: int, sampleRate: int}
     */
    public function create(AuthSession $session): array
    {
        $user = $session->getUser();
        $this->moderation->assertCanInteract($user->getId());
        $id = Uuid::v7()->toRfc4122();
        $credential = $id . '.' . bin2hex(random_bytes(32));
        $item = $this->cache->getItem('voice_' . $id);
        $item
            ->set(
                [
                    'sessionId' => $session->getId()->toRfc4122(),
                    'hash'      => hash('sha256', $credential),
                    'issuedAt'  => $this->clock->now()->getTimestamp(),
                    'claimed'   => false,
                ],
            )
            ->expiresAfter(self::SESSION_TTL);
        $this->cache->save($item);

        return [
            'id'                 => $id,
            'url'                => '/api/v1/chat-transcriptions/stream',
            'credential'         => $credential,
            'expiresIn'          => 30,
            'maxDurationSeconds' => AudioNormalizer::MAX_DURATION_SECONDS,
            'sampleRate'         => 24000,
        ];
    }

    public function authenticate(#[\SensitiveParameter] string $credential): AuthSession
    {
        $record = $this->record($credential);
        $session = $this->sessions->find($record['sessionId']);
        if (!$session instanceof AuthSession || !$session->isRefreshActive($this->clock->now())) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }

        return $session;
    }

    /** @return array{language: string} */
    public function authorize(string $id, #[\SensitiveParameter] string $credential, string $operation): array
    {
        if ($id !== explode('.', $credential, 2)[0]) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }
        $lock = $this->locks->createLock('voice_' . $id);
        $lock->acquire(true);
        try {
            $record = $this->record($credential);
            $session = $this->authenticate($credential);
            $user = $session->getUser();
            if ('release' === $operation) {
                $this->cache->deleteItem('voice_' . $id);

                return ['language' => $user->getPreferredLanguage()];
            }
            $this->moderation->assertCanInteract($user->getId());
            if ('claim' === $operation) {
                if ($record['claimed'] || $record['issuedAt'] + 30 <= $this->clock->now()->getTimestamp()) {
                    throw new ApiException(401, 'AUTH_REQUIRED');
                }
                $record['claimed'] = true;
                $item = $this->cache->getItem('voice_' . $id);
                $expiry = $this->clock->now()->setTimestamp($record['issuedAt'] + self::SESSION_TTL);
                $item
                    ->set($record)
                    ->expiresAt($expiry);
                $this->cache->save($item);
            } elseif ('check' !== $operation || !$record['claimed']) {
                throw new ApiException(401, 'AUTH_REQUIRED');
            }

            return ['language' => $user->getPreferredLanguage()];
        } finally {
            $lock->release();
        }
    }

    /** @return array{sessionId: string, hash: string, issuedAt: int, claimed: bool} */
    private function record(#[\SensitiveParameter] string $credential): array
    {
        $parts = explode('.', $credential, 2);
        if (2 !== count($parts) || !Uuid::isValid($parts[0]) || 64 !== strlen($parts[1])) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }
        $record = $this->cache->getItem('voice_' . $parts[0])->get();
        if (!is_array($record) || !is_string($record['sessionId'] ?? null)
            || !is_string($record['hash'] ?? null) || !is_int($record['issuedAt'] ?? null)
            || !is_bool($record['claimed'] ?? null)
            || $record['issuedAt'] + self::SESSION_TTL <= $this->clock->now()->getTimestamp()
            || !hash_equals($record['hash'], hash('sha256', $credential))) {
            throw new ApiException(401, 'AUTH_REQUIRED');
        }

        return $record;
    }
}
