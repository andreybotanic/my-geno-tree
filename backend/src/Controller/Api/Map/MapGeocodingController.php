<?php

namespace App\Controller\Api\Map;

use App\Api\ApiException;
use App\Application\Map\NominatimGeocoder;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class MapGeocodingController
{
    public function __construct(private NominatimGeocoder $geocoder)
    {
    }

    #[Route('/api/v1/map/geocoding/search', name: 'api_map_geocoding_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = $request->query->get('q');
        if (!is_string($query)) {
            $this->invalid('q', 'text_required');
        }

        return new JsonResponse(['items' => $this->geocoder->search(
            $query,
            $user->getPreferredLanguage(),
        )]);
    }

    #[Route('/api/v1/map/geocoding/reverse', name: 'api_map_geocoding_reverse', methods: ['GET'])]
    public function reverse(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $latitude = $request->query->get('latitude');
        $longitude = $request->query->get('longitude');
        if (!is_string($latitude) || !is_numeric($latitude)) {
            $this->invalid('latitude', 'number_required');
        }
        if (!is_string($longitude) || !is_numeric($longitude)) {
            $this->invalid('longitude', 'number_required');
        }

        return new JsonResponse($this->geocoder->reverse(
            (float)$latitude,
            (float)$longitude,
            $user->getPreferredLanguage(),
        ));
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GEOCODING_QUERY_INVALID', 'geocoding_query_invalid', [$field => [$error]]);
    }
}
