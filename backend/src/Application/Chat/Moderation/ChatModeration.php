<?php

namespace App\Application\Chat\Moderation;

use App\Api\ApiException;
use App\Entity\SuspiciousChatMessage;
use App\Entity\User;
use App\Repository\SuspiciousChatMessageRepository;
use App\Repository\UserRepository;
use App\Security\ChatVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/** Persistent sanctions and audit, independent of short-lived conversations. */
final readonly class ChatModeration
{
    private const string UNAVAILABLE = 'chat_moderation_unavailable';

    public function __construct(
        private ModerationPolicy $policy,
        private EntityManagerInterface $manager,
        private UserRepository $users,
        private SuspiciousChatMessageRepository $messages,
        private ClockInterface $clock,
        private LockFactory $locks,
        private AuthorizationCheckerInterface $authorization,
        #[Target('chat_tokens')] private RateLimiterFactoryInterface $tokens,
        #[Target('cache.chat_access')] private CacheItemPoolInterface $cache,
    ) {
    }

    /** @return array{status: string, warnings: int, warningLimit: int, banCount: int,
     * bannedUntil: ?string, unavailableUntil: ?string, serverTime: string}
     */
    public function access(Uuid $id): array
    {
        $user = $this->users->withChatBan($id) ?? throw new ApiException(401, 'AUTH_REQUIRED');
        $ban = $user->getLastChatBan();
        $now = $this->clock->now();
        $until = $this->cache->getItem(self::UNAVAILABLE)->get();
        $unavailable = is_int($until) && $until > $now->getTimestamp();
        $status = $unavailable ? 'unavailable' : 'allowed';
        if ($user->hasChatBan($now)) {
            $status = null === $ban?->getEndsAt() ? 'permanent_ban' : 'temporary_ban';
        }

        return [
            'status'           => $status,
            'warnings'         => $user->getChatWarnings(),
            'warningLimit'     => 3,
            'banCount'         => $ban?->getNumber() ?? 0,
            'bannedUntil'      => $ban?->getEndsAt()?->format(\DateTimeInterface::ATOM),
            'unavailableUntil' => $unavailable ? $now->setTimestamp($until)->format(\DateTimeInterface::ATOM) : null,
            'serverTime'       => $now->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public function assertCanInteract(Uuid $user): void
    {
        if (!$this->authorization->isGranted(ChatVoter::INTERACT, $user)) {
            throw new ApiException(403, 'CHAT_BANNED');
        }
        $until = $this->cache->getItem(self::UNAVAILABLE)->get();
        if (is_int($until) && $until > $this->clock->now()->getTimestamp()) {
            throw new ApiException(503, 'CHAT_UNAVAILABLE');
        }
    }

    public function unavailable(): void
    {
        $item = $this->cache->getItem(self::UNAVAILABLE);
        $item->set($this->clock->now()->getTimestamp() + 30)->expiresAfter(30);
        $this->cache->save($item);
    }

    public function recordAssessment(Uuid $id, string $key, string $text, float $risk): int
    {
        if (!is_finite($risk) || $risk < 0 || $risk > 1) {
            throw new \InvalidArgumentException('Invalid moderation assessment.');
        }
        if ($risk < $this->policy->auditThreshold) {
            return 0;
        }
        $lock = $this->locks->createLock('chat_moderation_' . $id->toRfc4122());
        $lock->acquire(true);
        try {
            $blocked = false;
            $number = $this->manager->wrapInTransaction(function () use ($id, $key, $text, $risk, &$blocked): int {
                $user = $this->manager->find(User::class, $id);
                if (null === $user) {
                    throw new ApiException(401, 'AUTH_REQUIRED');
                }
                $this->manager->lock($user, LockMode::PESSIMISTIC_WRITE);
                $this->manager->refresh($user);
                $saved = $this->messages->findOperation($key);
                if (null !== $saved) {
                    return $saved->getWarningNumber();
                }
                $now = $this->clock->now();
                $blocked = $user->hasChatBan($now);
                $number = !$blocked && $risk >= $this->policy->warningThreshold ? $user->getChatWarnings() + 1 : 0;
                $ban = $number > 0
                    ? $user->warnAboutChat($now, $this->policy->banSeconds, $this->policy->temporaryBans)
                    : null;
                $this->manager->persist(new SuspiciousChatMessage($user, $key, $text, $risk, $number, $now));
                if (null !== $ban) {
                    $this->manager->persist($ban);
                    // Check SQL constraints before debiting the existing cache-backed quota.
                    $this->manager->flush();
                    $limiter = $this->tokens->create($id->toRfc4122());
                    do {
                        $charge = min($this->policy->penaltyTokens, max(0, $limiter->consume(0)->getRemainingTokens()));
                    } while ($charge > 0 && !$limiter->consume($charge)->isAccepted());
                    $ban->chargeTokens($charge);
                }

                return $number;
            });
            if ($blocked) {
                throw new ApiException(403, 'CHAT_BANNED');
            }

            return $number;
        } finally {
            $lock->release();
        }
    }
}
