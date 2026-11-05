<?php

namespace App\Application\TreeMerge;

use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/** Private, ephemeral events use the same login-session boundary as conversation memory. */
final readonly class TreeMergeEvents
{
    public function __construct(
        #[Target('defaultHub')] private HubInterface $hub,
        #[Target('cache.merge_session')] private CacheItemPoolInterface $cache,
        private RequestStack $requests,
        private LoggerInterface $logger,
        #[Autowire('%env(MERCURE_JWT_ISSUER)%')] private string $topicOrigin,
    ) {
    }

    public function sessionTopic(AuthSession $session): string
    {
        return $this->topicOrigin . '/api/v1/merge-events/sessions/' . $session->getId()->toRfc4122() . '/drafts/';
    }

    /** Rebind progress to the committed attempt/proposal version for this login session. */
    public function begin(TreeMergeDraft $draft): void
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession) {
            return;
        }
        $item = $this->cache->getItem('stream_' . $draft->id->toRfc4122() . '_' . $draft->attemptId);
        $this->cache->save($item->set([
            'topic'            => $this->sessionTopic($session) . $draft->id->toRfc4122(),
            'version'          => $draft->version,
            'attemptStartedAt' => $draft->attemptStartedAt?->format(\DateTimeInterface::ATOM),
        ])->expiresAfter(3600));
    }

    /** @param list<string> $progress
     * @param array{input: int, output: int, total: int} $usage
     */
    public function progress(string $draftId, string $attemptId, array $progress, array $usage): void
    {
        /** @var array{topic: string, version: int, attemptStartedAt: string}|null $binding */
        $binding = $this->cache->getItem('stream_' . $draftId . '_' . $attemptId)->get();
        if (null === $binding) {
            return;
        }
        $this->publish($binding['topic'], ['kind' => 'progress', 'id' => $draftId, 'version' => $binding['version'],
            'attemptId'                           => $attemptId, 'attemptStartedAt' => $binding['attemptStartedAt'], 'progress' => $progress, 'tokenUsage' => $usage]);
    }

    /** @param array<string, mixed> $draft */
    public function draft(AuthSession $session, array $draft): void
    {
        $id = $draft['id'] ?? null;
        if (is_string($id)) {
            $this->publish($this->sessionTopic($session) . $id, ['kind' => 'draft', 'draft' => $draft]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, JSON_THROW_ON_ERROR), private: true));
        } catch (\Throwable $error) {
            // A committed mutation remains successful when the hub is temporarily unavailable.
            $this->logger->warning('Tree merge event delivery failed: ' . $error::class);
        }
    }
}
