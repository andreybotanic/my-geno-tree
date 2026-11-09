<?php

namespace App\Controller\Api\Profile;

use App\Api\MediaResponseFactory;
use App\Api\View\UserView;
use App\Application\Media\CropAreaPolicy;
use App\Application\Media\MediaService;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final readonly class AvatarController
{
    public function __construct(
        private CropAreaPolicy $input,
        private MediaService $media,
        private MediaResponseFactory $responses,
        private ObjectMapperInterface $mapper,
    ) {
    }

    #[Route('/api/v1/me/avatar', name: 'api_avatar_get', methods: ['GET'])]
    public function get(#[CurrentUser] User $user): Response
    {
        $variant = $this->media->avatar($user);

        return $this->responses->create(
            $variant,
            $this->media->contents($variant),
        );
    }

    #[Route('/api/v1/me/avatar', name: 'api_avatar_upload', methods: ['POST'])]
    #[Serialize]
    public function upload(
        Request $request,
        #[CurrentUser] User $user,
    ): UserView {
        $this->media->uploadAvatar(
            $user,
            $this->input->uploadedFile($request),
            $this->input->fromRequest($request),
        );

        return $this->mapper->map($user, UserView::class);
    }

    #[Route('/api/v1/me/avatar', name: 'api_avatar_delete', methods: ['DELETE'])]
    #[Serialize]
    public function delete(#[CurrentUser] User $user): UserView
    {
        $this->media->deleteAvatar($user);

        return $this->mapper->map($user, UserView::class);
    }
}
