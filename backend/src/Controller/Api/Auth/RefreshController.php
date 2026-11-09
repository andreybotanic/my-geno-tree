<?php

namespace App\Controller\Api\Auth;

use App\Api\ApiException;
use App\Api\View\AuthView;
use App\Application\Auth\RefreshSessionService;
use App\Http\RefreshCookieFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RefreshController extends AbstractController
{
    public function __construct(
        private readonly RefreshSessionService $refresh,
        private readonly ObjectMapperInterface $mapper,
        private readonly RefreshCookieFactory $cookies,
    ) {
    }

    #[Route('/api/v1/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $refreshToken = $request->cookies->get(RefreshCookieFactory::NAME);
        if (!is_string($refreshToken) || '' === $refreshToken) {
            throw new ApiException(JsonResponse::HTTP_UNAUTHORIZED, 'INVALID_REFRESH_TOKEN', 'invalid_refresh_token');
        }

        $bundle = $this->refresh->refresh($refreshToken);
        $response = $this->json($this->mapper->map($bundle, AuthView::class));
        $response->headers->setCookie($this->cookies->create($bundle));

        return $response;
    }
}
