<?php

namespace App\Controller\Api\Person;

use App\Api\RequestBodyMapper;
use App\Application\Person\SurnameLocalizationService;
use App\Entity\User;
use App\Entity\Value\Gender;
use App\Request\Person\LocalizeSurnameRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final readonly class SurnameLocalizationController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private SurnameLocalizationService $localizationService,
    ) {
    }

    #[Route('/api/v1/surname-localization', name: 'api_surname_localization', methods: ['POST'])]
    public function localize(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        /** @var LocalizeSurnameRequest $input */
        $input = $this->requestBody->map($request, LocalizeSurnameRequest::class);

        return new JsonResponse(['surname' => $this->localizationService->localize(
            trim($input->surname),
            Gender::from($input->sourceGender),
            Gender::from($input->targetGender),
            $user->getPreferredLanguage(),
        )]);
    }
}
