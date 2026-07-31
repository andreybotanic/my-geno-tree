<?php

namespace App\Controller\Api\Auth;

use App\Application\Auth\LogoutService;
use App\Entity\AuthSession;
use App\Http\RefreshCookieFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LogoutController
{
    public function __construct(
        private LogoutService $logout,
        private RefreshCookieFactory $cookies,
    ) {
    }

    #[Route('/api/v1/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->attributes->get(AuthSession::class);
        $refreshToken = $request->cookies->get(RefreshCookieFactory::NAME);
        $this->logout->logout(
            $session instanceof AuthSession ? $session : null,
            is_string($refreshToken) ? $refreshToken : null,
        );

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($this->cookies->expire());

        return $response;
    }
}
