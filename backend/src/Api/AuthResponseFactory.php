<?php

namespace App\Api;

use App\Api\View\UserView;
use App\Application\Auth\TokenBundle;
use App\Http\RefreshCookieFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class AuthResponseFactory
{
    public function __construct(
        private ViewMapper $views,
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
            'user'                 => $this->views->one($bundle->session->getUser(), UserView::class),
        ], $status);
        $response->headers->setCookie($this->cookies->create($bundle));

        return $response;
    }
}
