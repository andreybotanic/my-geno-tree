<?php

namespace App\Controller\Api;

use App\Api\ApiException;
use App\Application\Chat\Voice\ChatTranscriptionService;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/chat-transcriptions')]
#[IsGranted('ROLE_USER')]
final readonly class ChatTranscriptionController
{
    public function __construct(private ChatTranscriptionService $transcription)
    {
    }

    #[Route('', name: 'api_chat_transcribe', methods: ['POST'])]
    public function transcribe(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        if ([] !== $request->request->all() || ['audio'] !== array_keys($request->files->all())) {
            throw new ApiException(422, 'CHAT_AUDIO_REQUIRED');
        }
        $file = $request->files->get('audio');
        if (!$file instanceof UploadedFile) {
            throw new ApiException(422, 'CHAT_AUDIO_FORMAT');
        }

        return new JsonResponse($this->transcription->transcribe($user, $file));
    }
}
