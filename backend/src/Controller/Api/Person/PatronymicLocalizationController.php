<?php

namespace App\Controller\Api\Person;

use App\Api\RequestBodyMapper;
use App\Application\Person\SurnameLocalizationService;
use App\Entity\User;
use App\Entity\Value\Gender;
use App\Request\Person\LocalizePatronymicRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final readonly class PatronymicLocalizationController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private SurnameLocalizationService $localizationService,
    ) {
    }

    #[Route('/api/v1/patronymic-localization', name: 'api_patronymic_localization', methods: ['POST'])]
    public function localize(
        Request $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        /** @var LocalizePatronymicRequest $input */
        $input = $this->requestBody->map($request, LocalizePatronymicRequest::class);

        return new JsonResponse(['patronymic' => $this->localizationService->determinePatronymic(
            trim($input->fatherGivenName),
            Gender::from($input->childGender),
            $user->getPreferredLanguage(),
        )]);
    }
}
