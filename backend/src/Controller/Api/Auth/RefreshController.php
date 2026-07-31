<?php

namespace App\Controller\Api\Auth;

use App\Api\ApiException;
use App\Api\AuthResponseFactory;
use App\Application\Auth\RefreshSessionService;
use App\Http\RefreshCookieFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RefreshController
{
    public function __construct(
        private RefreshSessionService $refresh,
        private AuthResponseFactory $responseFactory,
    ) {
    }

    #[Route('/api/v1/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $refreshToken = $request->cookies->get(RefreshCookieFactory::NAME);
        if (!is_string($refreshToken) || '' === $refreshToken) {
            throw new ApiException(JsonResponse::HTTP_UNAUTHORIZED, 'INVALID_REFRESH_TOKEN', 'invalid_refresh_token');
        }

        return $this->responseFactory->create(
            $this->refresh->refresh($refreshToken),
        );
    }
}
