<?php

namespace App\Controller\Api\Profile;

use App\Api\RequestBodyMapper;
use App\Api\View\UserView;
use App\Application\Profile\ProfileService;
use App\Entity\User;
use App\Request\Profile\ChangeLanguageRequest;
use App\Request\Profile\UpdateBloodNetworkPresetsRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final readonly class MeController
{
    public function __construct(
        private ObjectMapperInterface $mapper,
        private RequestBodyMapper $requestBody,
        private ProfileService $profile,
    ) {
    }

    #[Route('/api/v1/me', name: 'api_me_get', methods: ['GET'])]
    #[Serialize]
    public function get(#[CurrentUser] User $user): UserView
    {
        return $this->mapper->map($user, UserView::class);
    }

    #[Route('/api/v1/me', name: 'api_me_patch', methods: ['PATCH'])]
    #[Serialize]
    public function patch(
        Request $request,
        #[CurrentUser] User $user,
    ): UserView {
        $input = $this->requestBody->map(
            $request,
            ChangeLanguageRequest::class,
        );
        $this->profile->changeLanguage($user, $input->language);

        return $this->mapper->map($user, UserView::class);
    }

    #[Route('/api/v1/me/blood-network-presets', name: 'api_me_blood_network_presets_patch', methods: ['PATCH'])]
    #[Serialize]
    public function updateBloodNetworkPresets(
        Request $request,
        #[CurrentUser] User $user,
    ): UserView {
        $input = $this->requestBody->map(
            $request,
            UpdateBloodNetworkPresetsRequest::class,
        );
        $this->profile->changeBloodNetworkPresets($user, $input->presets);

        return $this->mapper->map($user, UserView::class);
    }
}
