<?php

namespace App\Controller\Api\Auth;

use App\Api\RequestBodyMapper;
use App\Api\View\AuthView;
use App\Application\Auth\LoginService;
use App\Http\RefreshCookieFactory;
use App\Request\Auth\LoginRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;

final class LoginController extends AbstractController
{
    public function __construct(
        private readonly RequestBodyMapper $requestBody,
        private readonly LoginService $login,
        private readonly ObjectMapperInterface $mapper,
        private readonly RefreshCookieFactory $cookies,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $input = $this->requestBody->map($request, LoginRequest::class);

        $bundle = $this->login->login($input);
        $response = $this->json($this->mapper->map($bundle, AuthView::class));
        $response->headers->setCookie($this->cookies->create($bundle));

        return $response;
    }
}
