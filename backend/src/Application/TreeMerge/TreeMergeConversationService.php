<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentContext;
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
        private TreeMergeDraftService $drafts,
        private TreeMergeChat $chat,
        private \App\Api\TreeMergeDraftViewFactory $views,
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
            $messages = $this->chat->messages($draft);
            foreach ($messages as $message) {
                if ('assistant' === $message['role'] && $requestId === $message['requestId'] && !isset($message['failureCode'])) {
                    return null;
                }
            }
            $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
            if ($draft->version !== $version || null === $last || $last['requestId'] !== $requestId) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if ('processing' === $draft->chatState && $draft->attemptStartedAt > $this->clock->now()->modify('-5 minutes')) {
                throw new ApiException(409, 'TREE_MERGE_PROCESSING');
            }
            if (!$this->limiter->create($draft->owner->getId()->toRfc4122())->consume()->isAccepted()) {
                throw new ApiException(429, 'TREE_MERGE_RATE_LIMIT');
            }

            return $draft->beginAttempt($this->clock->now(), $requestId);
        });
        if (null === $attempt) {
            return;
        }
        $this->events->begin($draft);
        $request = $this->requests->getCurrentRequest() ?? throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        $previousContext = $request->attributes->get(TreeMergeAgentContext::class);
        $previousDraft = $request->attributes->get(TreeMergeDraft::class);
        $context = new TreeMergeAgentContext($draft, $attempt);
        $request->attributes->set(TreeMergeAgentContext::class, $context);
        $request->attributes->set(TreeMergeDraft::class, $draft);
        try {
            $this->activity->step($draft, 'thinking');
            $answer = null;
            $failure = 'empty_response';
            try {
                $this->drafts->active($context->treeId);
                $text = $this->ai->respond($draft);
                if ('' !== trim($text)) {
                    $answer = $text;
                }
            } catch (\Throwable $error) {
                $this->logger->warning('Tree merge agent failed: ' . $error::class);
                $failure = $error instanceof ApiException && 'TREE_MERGE_TOKEN_LIMIT' === $error->getErrorCode()
                    ? 'token_limit' : 'provider_unavailable';
            }
            $this->entityManager->wrapInTransaction(function () use ($draft, $context, $requestId, $answer, $failure): void {
                $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($draft);
                try {
                    $this->drafts->active($context->treeId);
                } catch (ApiException) {
                    // A stopped, superseded or revoked turn cannot publish a late answer.
                    return;
                }
                if (null === $answer) {
                    $this->chat->append($draft, $requestId, $failure, true);
                }
                $draft->finishAttempt($this->clock->now(), null === $answer);
            });
            $session = $request->attributes->get(\App\Entity\AuthSession::class);
            if ($session instanceof \App\Entity\AuthSession) {
                $this->events->draft($session, $this->views->create($draft));
            }
        } finally {
            foreach ([TreeMergeAgentContext::class => $previousContext, TreeMergeDraft::class => $previousDraft] as $key => $value) {
                if (null === $value) {
                    $request->attributes->remove($key);
                } else {
                    $request->attributes->set($key, $value);
                }
            }
        }
    }

    public function stop(TreeMergeDraft $draft, mixed $requestId): void
    {
        if (!is_string($requestId) || '' === $requestId || strlen($requestId) > 100) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $requestId): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            $messages = $this->chat->messages($draft);
            $last = array_find(array_reverse($messages), static fn ($message) => 'user' === $message['role']);
            if (null === $last || $last['requestId'] !== $requestId) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if (array_any($messages, static fn ($message) => 'assistant' === $message['role'] && $message['requestId'] === $requestId)) {
                return;
            }
            $this->activity->stop($draft, $requestId);
            $this->chat->append($draft, $requestId, 'stopped', true);
            $draft->finishAttempt($this->clock->now());
        });
    }
}
