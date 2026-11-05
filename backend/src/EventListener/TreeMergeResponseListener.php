<?php

namespace App\EventListener;

use App\Application\TreeMerge\TreeMergeEvents;
use App\Entity\AuthSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** Publish controller results after their transactions have committed, never from Doctrine flush hooks. */
#[AsEventListener]
final readonly class TreeMergeResponseListener
{
    public function __construct(private TreeMergeEvents $events)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $session = $request->attributes->get(AuthSession::class);
        if (!$event->isMainRequest() || !$response instanceof JsonResponse || !$response->isSuccessful()
            || !$session instanceof AuthSession || !in_array($request->attributes->get('_route'), [
                'api_tree_merge_create', 'api_tree_merge_message',
                'api_tree_merge_stop', 'api_tree_merge_proposal', 'api_tree_merge_revalidate', 'api_tree_merge_apply',
            ], true)) {
            return;
        }
        /** @var array<string, mixed> $draft */
        $draft = json_decode((string)$response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->events->draft($session, $draft);
    }
}
