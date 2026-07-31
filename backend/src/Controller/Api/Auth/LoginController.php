<?php

namespace App\Controller\Api\Auth;

use App\Api\AuthResponseFactory;
use App\Api\RequestBodyMapper;
use App\Application\Auth\LoginService;
use App\Request\Auth\LoginRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LoginController
{
    public function __construct(
        private RequestBodyMapper $requestBody,
        private LoginService $login,
        private AuthResponseFactory $responseFactory,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $input = $this->requestBody->map($request, LoginRequest::class);

        return $this->responseFactory->create($this->login->login($input));
    }
}
