<?php

namespace App\Application\Map;

use App\Api\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NominatimGeocoder
{
    private const int CACHE_TTL = 7 * 24 * 60 * 60;

    public function __construct(
        #[Target('nominatim.client')]
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.map_geocoding')]
        private CacheInterface $cache,
        #[Target('nominatim_global')]
        private RateLimiterFactoryInterface $rateLimiter,
    ) {
    }

    /** @return list<array{name: string, coordinates: array{latitude: float, longitude: float}}> */
    public function search(string $query, string $language): array
    {
        return array_map(
            static fn (array $candidate): array => $candidate['place'],
            $this->searchCandidates($query, $language),
        );
    }

    /** @return array{name: string, coordinates: array{latitude: float, longitude: float}}|null */
    public function bestMatch(string $query, string $language): ?array
    {
        $best = null;
        $bestScore = PHP_INT_MIN;
        foreach ($this->searchCandidates($query, $language) as $candidate) {
            $score = PHP_INT_MIN;
            foreach ($candidate['matchNames'] as $name) {
                $score = max($score, $this->similarity($query, $name));
            }
            if ($score > $bestScore) {
                $best = $candidate['place'];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return list<array{place: array{name: string, coordinates: array{latitude: float, longitude: float}}, matchNames: list<string>}> */
    private function searchCandidates(string $query, string $language): array
    {
        $normalized = trim($query);
        if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 200) {
            $this->invalid('q', 'text_length_invalid');
        }
        $locale = $this->locale($language);
        $key = 'search.' . hash('sha256', "namedetails-v1\0" . $locale . "\0" . mb_strtolower($normalized));

        return $this->cache->get($key, function (ItemInterface $item) use ($normalized, $locale): array {
            $item->expiresAfter(self::CACHE_TTL);
            $payload = $this->request('/search', [
                'q'               => $normalized,
                'format'          => 'jsonv2',
                'limit'           => 5,
                'featureType'     => 'settlement',
                'addressdetails'  => 0,
                'namedetails'     => 1,
                'accept-language' => $locale,
            ]);
            $results = [];
            foreach (array_slice($payload, 0, 5) as $candidate) {
                $place = $this->place($candidate);
                if (null !== $place) {
                    $results[] = $place;
                }
            }

            return $results;
        });
    }

    /** @return array{name: string, coordinates: array{latitude: float, longitude: float}} */
    public function reverse(float $latitude, float $longitude, string $language): array
    {
        $this->coordinates($latitude, $longitude);
        $locale = $this->locale($language);
        $key = 'reverse.' . hash('sha256', sprintf('%s\0%.7F\0%.7F', $locale, $latitude, $longitude));

        return $this->cache->get($key, function (ItemInterface $item) use ($latitude, $longitude, $locale): array {
            $item->expiresAfter(self::CACHE_TTL);
            $payload = $this->request('/reverse', [
                'lat'             => $latitude,
                'lon'             => $longitude,
                'format'          => 'jsonv2',
                'zoom'            => 18,
                'addressdetails'  => 0,
                'accept-language' => $locale,
            ]);
            $place = $this->place($payload);
            if (null === $place) {
                throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable');
            }

            return $place['place'];
        });
    }

    /**
     * @param array<string, int|float|string> $query
     *
     * @return array<array-key, mixed>
     */
    private function request(string $path, array $query): array
    {
        try {
            $this->rateLimiter->create('nominatim-public-api')->reserve(1, 3.0)->wait();
            $payload = $this->httpClient->request('GET', $path, ['query' => $query])->toArray();
        } catch (MaxWaitDurationExceededException) {
            throw new ApiException(JsonResponse::HTTP_TOO_MANY_REQUESTS, 'RATE_LIMIT_EXCEEDED', 'rate_limit_exceeded');
        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $exception) {
            throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable', previous: $exception);
        }

        return $payload;
    }

    /** @return array{place: array{name: string, coordinates: array{latitude: float, longitude: float}}, matchNames: list<string>}|null */
    private function place(mixed $candidate): ?array
    {
        if (!is_array($candidate)) {
            return null;
        }
        $name = $candidate['display_name'] ?? null;
        $latitude = $candidate['lat'] ?? null;
        $longitude = $candidate['lon'] ?? null;
        if (!is_string($name) || !is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }
        $latitude = (float)$latitude;
        $longitude = (float)$longitude;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }
        $name = mb_substr(trim($name), 0, 500);
        if ('' === $name) {
            return null;
        }

        $matchNames = [$name];
        if (is_array($candidate['namedetails'] ?? null)) {
            foreach ($candidate['namedetails'] as $alternateName) {
                if (is_string($alternateName) && '' !== trim($alternateName)) {
                    $matchNames[] = $alternateName;
                }
            }
        }

        return [
            'place' => [
                'name'        => $name,
                'coordinates' => [
                    'latitude'  => $latitude,
                    'longitude' => $longitude,
                ],
            ],
            'matchNames' => array_values(array_unique($matchNames)),
        ];
    }

    private function coordinates(float $latitude, float $longitude): void
    {
        if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
            $this->invalid('latitude', 'coordinate_invalid');
        }
        if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            $this->invalid('longitude', 'coordinate_invalid');
        }
    }

    private function locale(string $language): string
    {
        return 'ru' === $language ? 'ru' : 'en';
    }

    private function similarity(string $query, string $candidate): int
    {
        $query = $this->normalizedName($query);
        $candidate = $this->normalizedName($candidate);
        if ($query === $candidate) {
            return 1_000_000;
        }
        $candidateWords = array_unique(explode(' ', $candidate));
        if (in_array($query, $candidateWords, true)) {
            return 750_000 + mb_strlen($query);
        }
        if (str_contains($candidate, $query)) {
            return 500_000 + mb_strlen($query);
        }
        $queryWords = array_unique(explode(' ', $query));
        $sharedWords = count(array_intersect($queryWords, $candidateWords));

        return $sharedWords * 10_000 - abs(count($queryWords) - count($candidateWords));
    }

    private function normalizedName(string $value): string
    {
        $value = mb_strtolower($value);
        $normalized = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $value);
        if (null === $normalized) {
            return '';
        }

        return trim($normalized);
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GEOCODING_QUERY_INVALID', 'geocoding_query_invalid', [$field => [$error]]);
    }
}
