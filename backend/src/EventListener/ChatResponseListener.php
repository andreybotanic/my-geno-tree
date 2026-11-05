<?php

namespace App\EventListener;

use App\Application\Chat\ChatEvents;
use App\Entity\AuthSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener]
final readonly class ChatResponseListener
{
    public function __construct(private ChatEvents $events)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $session = $request->attributes->get(AuthSession::class);
        if ($event->isMainRequest() && true === $request->attributes->get('_chat_event') && $session instanceof AuthSession
            && $response instanceof JsonResponse && $response->isSuccessful()) {
            /** @var array<string, mixed> $conversation */
            $conversation = json_decode((string)$response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $this->events->conversation($session, $conversation);
        }
    }
}
