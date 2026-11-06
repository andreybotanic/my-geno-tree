<?php

namespace App\Controller\Api;

use App\Api\RequestBodyMapper;
use App\Application\Chat\ChatInterface;
use App\Http\ChatValueResolver;
use App\Model\Conversation;
use App\Security\ChatVoter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Mercure\Authorization;
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
    public function events(
        #[ValueResolver(ChatValueResolver::class)]
        Conversation $conversation,
        Request $request,
        Authorization $authorization,
        ClockInterface $clock,
        #[Autowire('%app.chat_topic_prefix%')]
        string $topicPrefix,
    ): JsonResponse {
        $prefix = str_replace('{sessionId}', $conversation->sessionId->toRfc4122(), $topicPrefix);
        $cookie = $authorization
            ->createCookie(
                $request,
                ['urlpattern' => [$prefix . '*']],
                additionalClaims: [
                    'exp' => $clock->now()->modify('+5 minutes'),
                    'sub' => $conversation->sessionId->toRfc4122(),
                ],
            )
            ->withDomain(null);
        $response = new JsonResponse(
            [
                'url'       => '/.well-known/mercure?match=' . rawurlencode($prefix . $conversation->id->toRfc4122()),
                'expiresIn' => 300,
            ],
            headers: ['Cache-Control' => 'no-store'],
        );
        $response->headers->setCookie($cookie);

        return $response;
    }

    #[Route('/chat-events/authorize', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function authorize(): Response
    {
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }
}
