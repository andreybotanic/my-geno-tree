<?php

namespace App\Controller\Api;

use App\Api\RequestBodyMapper;
use App\Application\Chat\ChatInterface;
use App\Application\Chat\MercureChatTransport;
use App\Http\ChatValueResolver;
use App\Model\Conversation;
use App\Security\ChatVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
final readonly class ChatController
{
    public function __construct(private ChatInterface $chat, private RequestBodyMapper $body)
    {
    }

    #[Route('/chats/{chatId}', name: 'api_chat_get', methods: ['GET'])]
    #[IsGranted(ChatVoter::USE, subject: 'conversation', statusCode: 404)]
    public function get(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation): JsonResponse
    {
        return new JsonResponse($this->chat->snapshot($conversation));
    }

    #[Route('/chats/{chatId}/messages', name: 'api_chat_message', methods: ['POST'])]
    #[IsGranted(ChatVoter::USE, subject: 'conversation', statusCode: 404)]
    public function message(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        return new JsonResponse($this->chat->snapshot($this->chat->send($conversation, $this->body->decodeObject($request))));
    }

    #[Route('/chats/{chatId}/stop', name: 'api_chat_stop', methods: ['POST'])]
    #[IsGranted(ChatVoter::USE, subject: 'conversation', statusCode: 404)]
    public function stop(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        return new JsonResponse($this->chat->snapshot($this->chat->stop($conversation, $this->body->decodeObject($request)['requestId'] ?? null)));
    }

    #[Route('/chats/{chatId}/reset', name: 'api_chat_reset', methods: ['POST'])]
    #[IsGranted(ChatVoter::USE, subject: 'conversation', statusCode: 404)]
    public function reset(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request): JsonResponse
    {
        $this->chat->reset($conversation, $this->body->decodeObject($request)['version'] ?? null);

        return new JsonResponse(null, 204);
    }

    #[Route('/chats/{chatId}/events', name: 'api_chat_events', methods: ['POST'])]
    #[IsGranted(ChatVoter::USE, subject: 'conversation', statusCode: 404)]
    public function events(#[ValueResolver(ChatValueResolver::class)] Conversation $conversation, Request $request, MercureChatTransport $events): JsonResponse
    {
        return $events->subscribe($request, $conversation);
    }

    #[Route('/chat-events/authorize', methods: ['GET'])]
    public function authorize(Request $request, MercureChatTransport $events): Response
    {
        $events->authorize($request);

        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }
}
