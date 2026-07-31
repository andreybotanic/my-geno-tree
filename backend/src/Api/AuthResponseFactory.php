<?php

namespace App\Api;

use App\Application\Auth\TokenBundle;
use App\Http\RefreshCookieFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class AuthResponseFactory
{
    public function __construct(
        private UserViewFactory $userView,
        private RefreshCookieFactory $cookies,
    ) {
    }

    public function create(
        TokenBundle $bundle,
        int $status = JsonResponse::HTTP_OK,
    ): JsonResponse {
        $response = new JsonResponse([
            'accessToken'          => $bundle->accessToken,
            'accessTokenExpiresAt' => $bundle->accessExpiresAt->format(DATE_ATOM),
            'user'                 => $this->userView->create($bundle->session->getUser()),
        ], $status);
        $response->headers->setCookie($this->cookies->create($bundle));

        return $response;
    }
}
