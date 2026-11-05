<?php

namespace App\Controller\Api;

use App\Api\ApiException;
use App\Api\ChatViewFactory;
use App\Api\RequestBodyMapper;
use App\Application\Chat\ChatSubscription;
use App\Application\Chat\ConversationService;
use App\Http\ChatValueResolver;
use App\Model\Conversation;
use App\Security\ChatVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/chats/{chatId}')]
#[IsGranted(ChatVoter::USE, subject: 'conversation', statusCode: 404)]
final readonly class ChatController
{
    public function __construct(private ConversationService $conversations, private ChatViewFactory $views, private RequestBodyMapper $body)
    {
    }

    #[Route('', name: 'api_chat_get', methods: ['GET'])]
    public function get(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation): JsonResponse
    {
        return new JsonResponse($this->views->create($conversation));
    }

    #[Route('/messages', name: 'api_chat_message', methods: ['POST'], defaults: ['_chat_event' => true])]
    public function message(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        return new JsonResponse($this->views->create($this->conversations->append($conversation, $this->body->decodeObject($request))));
    }

    #[Route('/process', name: 'api_chat_process', methods: ['POST'])]
    public function process(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        $payload = $this->body->decodeObject($request);
        if (2 !== count($payload) || array_diff(['version', 'requestId'], array_keys($payload))) {
            throw new ApiException(422, 'CHAT_INVALID');
        }

        return new JsonResponse($this->views->create($this->conversations->process($conversation, $payload['version'] ?? null, $payload['requestId'] ?? null)));
    }

    #[Route('/stop', name: 'api_chat_stop', methods: ['POST'], defaults: ['_chat_event' => true])]
    public function stop(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        return new JsonResponse($this->views->create($this->conversations->stop($conversation, $this->body->decodeObject($request)['requestId'] ?? null)));
    }

    #[Route('/reset', name: 'api_chat_reset', methods: ['POST'])]
    public function reset(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        $this->conversations->reset($conversation, $this->body->decodeObject($request)['version'] ?? null);

        return new JsonResponse(null, 204);
    }

    #[Route('/events', name: 'api_chat_events', methods: ['POST'])]
    public function events(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request, ChatSubscription $subscriptions): JsonResponse
    {
        return $subscriptions->create($request, $conversation);
    }
}
