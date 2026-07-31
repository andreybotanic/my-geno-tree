<?php

namespace App\Controller\Api\Auth;

use App\Api\AuthResponseFactory;
use App\Api\RequestBodyMapper;
use App\Application\Auth\RegistrationService;
use App\Request\Auth\RegisterRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RegisterController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private RegistrationService $registration,
        private AuthResponseFactory $responseFactory,
    ) {
    }

    #[Route('/api/v1/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $input = $this->requestBody->map($request, RegisterRequest::class);
        $bundle = $this->registration->register($input);

        return $this->responseFactory->create(
            $bundle,
            JsonResponse::HTTP_CREATED,
        );
    }
}
