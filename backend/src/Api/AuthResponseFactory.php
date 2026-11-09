<?php

namespace App\Api;

use App\Api\View\UserView;
use App\Application\Auth\TokenBundle;
use App\Http\RefreshCookieFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class AuthResponseFactory
{
    public function __construct(
        private SerializerInterface $serializer,
        private ObjectMapperInterface $mapper,
        private RefreshCookieFactory $cookies,
    ) {
    }

    public function create(
        TokenBundle $bundle,
        int $status = JsonResponse::HTTP_OK,
    ): JsonResponse {
        $response = JsonResponse::fromJsonString(
            $this->serializer->serialize(
                [
                    'accessToken'          => $bundle->accessToken,
                    'accessTokenExpiresAt' => $bundle->accessExpiresAt->format(DATE_ATOM),
                    'user'                 => $this->mapper->map($bundle->session->getUser(), UserView::class),
                ],
                'json',
            ),
            $status,
        );
        $response->headers->setCookie($this->cookies->create($bundle));

        return $response;
    }
}
