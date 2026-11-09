<?php

namespace App\Controller\Api\Auth;

use App\Api\RequestBodyMapper;
use App\Api\View\AuthView;
use App\Application\Auth\RegistrationService;
use App\Http\RefreshCookieFactory;
use App\Request\Auth\RegisterRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly RequestBodyMapper $requestBody,
        private readonly RegistrationService $registration,
        private readonly ObjectMapperInterface $mapper,
        private readonly RefreshCookieFactory $cookies,
    ) {
    }

    #[Route('/api/v1/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $input = $this->requestBody->map($request, RegisterRequest::class);
        $bundle = $this->registration->register($input);

        $response = $this->json($this->mapper->map($bundle, AuthView::class), JsonResponse::HTTP_CREATED);
        $response->headers->setCookie($this->cookies->create($bundle));

        return $response;
    }
}
