<?php

namespace App\Controller\Api;

use App\Api\ApiException;
use App\Api\RequestBodyMapper;
use App\Application\Chat\Voice\ChatVoiceSessions;
use App\Entity\AuthSession;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/chat-transcription-sessions')]
#[IsGranted('ROLE_USER')]
final readonly class ChatVoiceSessionController
{
    public function __construct(private ChatVoiceSessions $sessions, private RequestBodyMapper $body)
    {
    }

    #[Route('', name: 'api_chat_voice_session', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ([] !== $this->body->decodeObject($request)) {
            throw new ApiException(422, 'INVALID_REQUEST');
        }
        $session = $request->attributes->get(AuthSession::class);
        assert($session instanceof AuthSession);

        return new JsonResponse($this->sessions->create($session), 201, ['Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/authorize', name: 'api_chat_voice_authorize', methods: ['POST'])]
    public function authorize(string $id, Request $request): JsonResponse
    {
        $body = $this->body->decodeObject($request);
        if (['operation'] !== array_keys($body) || !in_array($body['operation'], ['claim', 'check', 'release'], true)) {
            throw new ApiException(422, 'INVALID_REQUEST');
        }

        return new JsonResponse(
            $this->sessions->authorize(
                $id,
                substr($request->headers->get('Authorization', ''), 7),
                $body['operation'],
            ),
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
