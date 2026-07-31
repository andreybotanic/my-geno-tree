<?php

namespace App\Controller\Api\Profile;

use App\Api\RequestBodyMapper;
use App\Api\UserViewFactory;
use App\Application\Profile\ProfileService;
use App\Entity\User;
use App\Request\Profile\ChangeLanguageRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[IsGranted('ROLE_USER')]
final readonly class MeController
{
    public function __construct(
        private UserViewFactory $userView,
        private RequestBodyMapper $requestBody,
        private ProfileService $profile,
    ) {
    }

    #[Route('/api/v1/me', name: 'api_me_get', methods: ['GET'])]
    public function get(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->userView->create($user));
    }

    #[Route('/api/v1/me', name: 'api_me_patch', methods: ['PATCH'])]
    public function patch(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $input = $this->requestBody->map(
            $request,
            ChangeLanguageRequest::class,
        );
        $this->profile->changeLanguage($user, $input->language);

        return new JsonResponse($this->userView->create($user));
    }
}
