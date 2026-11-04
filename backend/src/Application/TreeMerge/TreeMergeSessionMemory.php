<?php

namespace App\Application\TreeMerge;

use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;

/** Conversation contents live in RAM, scoped to the authenticated session, never in ORM columns. */
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final readonly class TreeMergeSessionMemory
{
    public function __construct(#[Target('cache.merge_session')] private CacheItemPoolInterface $cache, private RequestStack $requests)
    {
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $draft = $event->getObject();
        if (!$draft instanceof TreeMergeDraft || null === ($key = $this->key($draft))) {
            return;
        }
        $item = $this->cache->getItem($key);
        /** @var list<array{id: string, requestId: string, role: string, content: string, createdAt: string, source?: bool, failureCode?: string}> $messages */
        $messages = $item->isHit() ? $item->get() : [];
        $draft->restoreSessionMessages($messages, $item->isHit());
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $this->save($event->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $this->save($event->getObject());
    }

    private function save(object $object): void
    {
        if (!$object instanceof TreeMergeDraft || null === ($key = $this->key($object))) {
            return;
        }
        // Versioned entries cannot expose a transaction that later rolls back: readers use the committed version.
        $item = $this->cache->getItem($key);
        $item->set($object->messages)->expiresAfter(3600);
        $this->cache->save($item);
        $object->chatSessionAvailable = true;
    }

    private function key(TreeMergeDraft $draft): ?string
    {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession) {
            return null;
        }

        return 'chat_' . $session->getId()->toRfc4122() . '_' . $draft->id->toRfc4122() . '_' . $draft->version;
    }
}
