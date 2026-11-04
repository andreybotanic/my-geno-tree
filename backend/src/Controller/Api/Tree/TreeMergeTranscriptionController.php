<?php

namespace App\Controller\Api\Tree;

use App\Api\ApiException;
use App\Application\TreeMerge\Voice\TreeMergeTranscriptionService;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/tree-merge-transcriptions')]
#[IsGranted('ROLE_USER')]
final readonly class TreeMergeTranscriptionController
{
    public function __construct(private TreeMergeTranscriptionService $transcription)
    {
    }

    #[Route('', name: 'api_tree_merge_transcribe', methods: ['POST'])]
    public function transcribe(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        if ([] !== $request->request->all() || ['audio'] !== array_keys($request->files->all())) {
            throw new ApiException(422, 'TREE_MERGE_AUDIO_REQUIRED');
        }
        $file = $request->files->get('audio');
        if (!$file instanceof UploadedFile) {
            throw new ApiException(422, 'TREE_MERGE_AUDIO_FORMAT');
        }

        return new JsonResponse($this->transcription->transcribe($user, $file));
    }
}
