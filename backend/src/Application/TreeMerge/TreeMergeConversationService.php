<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class TreeMergeConversationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TreeMergeAiInterface $ai,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private MergeActivity $activity,
        #[Target('tree_merge')] private RateLimiterFactoryInterface $limiter,
        private RequestStack $requests,
        private TreeMergeEvents $events,
    ) {
    }

    public function process(TreeMergeDraft $draft, mixed $version, mixed $requestId): void
    {
        if (!is_int($version) || !is_string($requestId)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $attempt = $this->entityManager->wrapInTransaction(function () use ($draft, $version, $requestId): ?string {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            foreach ($draft->messages as $message) {
                if ('assistant' === $message['role'] && $requestId === $message['requestId'] && !isset($message['failureCode'])) {
                    return null;
                }
            }
            $last = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role']);
            if ($draft->version !== $version || null === $last || $last['requestId'] !== $requestId
                || 'user' !== $last['role'] || in_array($draft->state, ['applied', 'cancelled'], true)) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if ('processing' === $draft->state && $draft->attemptStartedAt > $this->clock->now()->modify('-5 minutes')) {
                throw new ApiException(409, 'TREE_MERGE_PROCESSING');
            }
            if (!$this->limiter->create($draft->owner->getId()->toRfc4122())->consume()->isAccepted()) {
                throw new ApiException(429, 'TREE_MERGE_RATE_LIMIT');
            }

            return $draft->beginAttempt($this->clock->now());
        });
        if (null === $attempt) {
            return;
        }
        $attemptVersion = $draft->version;
        $this->events->begin($draft);
        $this->requests->getCurrentRequest()?->attributes->set(TreeMergeDraft::class, $draft);
        $this->activity->step($draft, 'thinking');
        $failure = 'reply_missing';
        try {
            $this->activity->assertRunning($draft);
            $this->ai->respond($draft, $attempt);
        } catch (\Throwable $error) {
            $this->logger->warning('Tree merge agent failed: ' . $error::class);
            $failure = $error instanceof ApiException && 'TREE_MERGE_TOKEN_LIMIT' === $error->getErrorCode()
                ? 'token_limit' : 'provider_unavailable';
        }
        // MCP writes through a separate request; reload its committed result before deciding whether it failed.
        $this->entityManager->wrapInTransaction(function () use ($draft, $attempt, $attemptVersion, $requestId, $failure): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            if ($draft->attemptId === $attempt && $draft->version === $attemptVersion && 'processing' === $draft->state) {
                $draft->processingFailed($this->clock->now(), $requestId, $failure);
            }
        });
    }

    public function stop(TreeMergeDraft $draft, mixed $requestId): void
    {
        if (!is_string($requestId) || '' === $requestId || strlen($requestId) > 100) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $requestId): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            $last = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role']);
            if (null === $last || $last['requestId'] !== $requestId || in_array($draft->state, ['applied', 'cancelled'], true)) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if (array_any($draft->messages, static fn ($message) => 'assistant' === $message['role'] && $message['requestId'] === $requestId && !isset($message['failureCode']))) {
                return;
            }
            $this->activity->stop($draft, $requestId);
            $draft->stop($requestId, $this->clock->now());
        });
    }
}
