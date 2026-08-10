<?php

namespace App\Controller\Api\Map;

use App\Api\ApiException;
use App\Application\Map\TileRateLimitGuard;
use App\Application\Map\YandexTileUrlSigner;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class MapTileController
{
    public function __construct(
        private YandexTileUrlSigner $signer,
        private TileRateLimitGuard $rateLimits,
    ) {
    }

    #[Route(
        '/api/v1/map/tiles/{z}/{x}/{y}.png',
        name: 'api_map_tile',
        requirements: ['z' => '\\d+', 'x' => '\\d+', 'y' => '\\d+'],
        methods: ['GET'],
    )]
    public function __invoke(
        int $z,
        int $x,
        int $y,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        if ($z < 0 || $z > 20 || $x < 0 || $y < 0) {
            $this->notFound();
        }
        $tilesPerAxis = 2 ** $z;
        if ($x >= $tilesPerAxis || $y >= $tilesPerAxis) {
            $this->notFound();
        }

        $this->rateLimits->consume($user);
        $response = new RedirectResponse(
            $this->signer->url($z, $x, $y),
            JsonResponse::HTTP_FOUND,
        );
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function notFound(): never
    {
        throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
    }
}
