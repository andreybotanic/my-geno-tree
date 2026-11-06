<?php

namespace App\Application\Map;

use App\Api\ApiException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GeoapifyGeocoder
{
    private const int CACHE_TTL = 7 * 24 * 60 * 60;
    private const int MAX_BATCH_SIZE = 1000;
    private const int SMALL_BATCH_POLLING_INTERVAL_SECONDS = 15;
    private const int MEDIUM_BATCH_POLLING_INTERVAL_SECONDS = 30;
    private const int LARGE_BATCH_POLLING_INTERVAL_SECONDS = 60;
    private const int SMALL_BATCH_MAX_SIZE = 100;
    private const int MEDIUM_BATCH_MAX_SIZE = 300;
    private const int QUARTER_CREDITS_PER_REQUEST = 4;
    private const int QUARTER_CREDITS_PER_BATCH_ITEM = 2;

    public function __construct(
        #[Target('geoapify.client')]
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.map_geocoding')]
        private CacheItemPoolInterface $cache,
        #[Target('geoapify_requests')]
        private RateLimiterFactoryInterface $requestRateLimiter,
        #[Target('geoapify_credits')]
        private RateLimiterFactoryInterface $creditRateLimiter,
        #[Autowire(env: 'GEOAPIFY_API_KEY')]
        private string $apiKey,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{name: string, coordinates: array{latitude: float, longitude: float}}>
     */
    public function autocomplete(string $query, string $language): array
    {
        $normalized = $this->query($query);
        $locale = $this->locale($language);
        $key = $this->cacheKey('autocomplete', $locale, $normalized);
        $item = $this->cache->getItem($key);
        if ($item->isHit() && is_array($item->get())) {
            /** @var list<array{name: string, coordinates: array{latitude: float, longitude: float}}> $places */
            $places = $item->get();

            return $places;
        }

        $payload = $this->request('GET', 'geocode/autocomplete', [
            'text'   => $normalized,
            'type'   => 'city',
            'lang'   => $locale,
            'limit'  => 5,
            'bias'   => 'countrycode:none',
            'format' => 'json',
        ]);
        $places = $this->places($payload);
        $item->set($places);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $places;
    }

    /** @return array{name: string, coordinates: array{latitude: float, longitude: float}} */
    public function reverse(float $latitude, float $longitude, string $language): array
    {
        $this->coordinates($latitude, $longitude);
        $locale = $this->locale($language);
        $key = $this->cacheKey('reverse', $locale, sprintf('%.7F\0%.7F', $latitude, $longitude));
        $item = $this->cache->getItem($key);
        if ($item->isHit() && is_array($item->get())) {
            /** @var array{name: string, coordinates: array{latitude: float, longitude: float}} $place */
            $place = $item->get();

            return $place;
        }

        $payload = $this->request('GET', 'geocode/reverse', [
            'lat'    => $latitude,
            'lon'    => $longitude,
            'type'   => 'city',
            'lang'   => $locale,
            'limit'  => 1,
            'format' => 'json',
        ]);
        $place = $this->places($payload)[0] ?? null;
        if (null === $place) {
            throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable');
        }
        $item->set($place);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $place;
    }

    /**
     * @param list<string> $queries
     *
     * @return array<string, array{name: string, coordinates: array{latitude: float, longitude: float}}>
     */
    public function batchBestMatches(array $queries, string $language): array
    {
        $locale = $this->locale($language);
        $matches = [];
        $uncached = [];
        foreach ($queries as $query) {
            $normalized = trim($query);
            if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 200) {
                continue;
            }
            $item = $this->cache->getItem($this->cacheKey('batch', $locale, $normalized));
            if ($item->isHit() && is_array($item->get())) {
                /** @var array{name: string, coordinates: array{latitude: float, longitude: float}} $place */
                $place = $item->get();
                $matches[$query] = $place;
                continue;
            }
            $uncached[$query] = $query;
        }

        foreach (array_chunk(array_values($uncached), self::MAX_BATCH_SIZE) as $batch) {
            try {
                $this->reserveCredits(count($batch) * self::QUARTER_CREDITS_PER_BATCH_ITEM);
                $job = $this->request('POST', 'batch/geocode/search', [
                    'type' => 'city',
                    'lang' => $locale,
                    'bias' => 'countrycode:none',
                ], $batch, [202]);
                $id = $job['id'] ?? null;
                if (!is_string($id) || '' === $id) {
                    throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable');
                }

                do {
                    $this->clock->sleep($this->pollingInterval(count($batch)));
                    [$status, $payload] = $this->requestWithStatus('GET', 'batch/geocode/search', ['id' => $id]);
                } while (202 === $status);

                if (200 !== $status || !is_array($payload)) {
                    throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable');
                }
                foreach ($payload as $index => $candidate) {
                    $query = is_array($candidate) && is_array($candidate['query'] ?? null)
                        && is_string($candidate['query']['text'] ?? null)
                        ? $candidate['query']['text']
                        : $batch[$index] ?? null;
                    if (!is_string($query)) {
                        continue;
                    }
                    $place = $this->bestBatchPlace($query, $candidate);
                    if (null === $place) {
                        continue;
                    }
                    $matches[$query] = $place;
                    $item = $this->cache->getItem($this->cacheKey('batch', $locale, $query));
                    $item->set($place);
                    $item->expiresAfter(self::CACHE_TTL);
                    $this->cache->save($item);
                }
            } catch (ApiException $exception) {
                if (in_array(
                    $exception->getErrorCode(),
                    ['GEOCODING_QUOTA_EXCEEDED', 'GEOCODING_UNAVAILABLE', 'RATE_LIMIT_EXCEEDED'],
                    true,
                )) {
                    break;
                }

                throw $exception;
            }
        }

        return $matches;
    }

    /**
     * @phpstan-param array<string, mixed> $payload
     *
     * @return list<array{name: string, coordinates: array{latitude: float, longitude: float}}>
     */
    private function places(array $payload): array
    {
        $results = $payload['results'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        $places = [];
        foreach (array_slice($results, 0, 5) as $candidate) {
            $place = $this->place($candidate);
            if (null !== $place) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /** @return array{name: string, coordinates: array{latitude: float, longitude: float}}|null */
    private function bestBatchPlace(string $query, mixed $candidate): ?array
    {
        $place = $this->place($candidate);
        if (null === $place || !is_array($candidate)) {
            return $place;
        }
        $names = [];
        foreach (['name', 'city', 'formatted'] as $field) {
            $value = $candidate[$field] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                $names[] = mb_substr(trim($value), 0, 500);
            }
        }
        if ([] === $names) {
            return $place;
        }

        $bestName = $names[0];
        $bestDistance = PHP_INT_MAX;
        foreach ($names as $name) {
            $distance = $this->levenshtein($this->normalizedName($query), $this->normalizedName($name));
            if ($distance < $bestDistance) {
                $bestName = $name;
                $bestDistance = $distance;
            }
        }
        $place['name'] = $bestName;

        return $place;
    }

    /** @return array{name: string, coordinates: array{latitude: float, longitude: float}}|null */
    private function place(mixed $candidate): ?array
    {
        if (!is_array($candidate)) {
            return null;
        }
        $latitude = $candidate['lat'] ?? null;
        $longitude = $candidate['lon'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }
        $latitude = (float)$latitude;
        $longitude = (float)$longitude;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }
        foreach (['formatted', 'name', 'city'] as $field) {
            $name = $candidate[$field] ?? null;
            if (is_string($name) && '' !== trim($name)) {
                return [
                    'name'        => mb_substr(trim($name), 0, 500),
                    'coordinates' => ['latitude' => $latitude, 'longitude' => $longitude],
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, float|int|string> $query
     * @param list<string>|null               $json
     * @param list<int>                       $acceptedStatuses
     *
     * @return array<mixed, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $query,
        ?array $json = null,
        array $acceptedStatuses = [200],
    ): array {
        [$status, $payload] = $this->requestWithStatus($method, $path, $query, $json);
        if (!in_array($status, $acceptedStatuses, true) || !is_array($payload)) {
            throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable');
        }

        return $payload;
    }

    /**
     * @param array<string, float|int|string> $query
     * @param list<string>|null               $json
     *
     * @return array{0: int, 1: mixed}
     */
    private function requestWithStatus(string $method, string $path, array $query, ?array $json = null): array
    {
        try {
            $this->reserveCredits(self::QUARTER_CREDITS_PER_REQUEST);
            $this->requestRateLimiter->create('geoapify-api-key')->reserve(1, 3.0)->wait();
            $options = ['query' => [...$query, 'apiKey' => $this->apiKey]];
            if (null !== $json) {
                $options['json'] = $json;
            }
            $response = $this->httpClient->request($method, $path, $options);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (MaxWaitDurationExceededException $exception) {
            throw new ApiException(JsonResponse::HTTP_TOO_MANY_REQUESTS, 'RATE_LIMIT_EXCEEDED', 'rate_limit_exceeded', previous: $exception);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $exception) {
            throw new ApiException(JsonResponse::HTTP_BAD_GATEWAY, 'GEOCODING_UNAVAILABLE', 'geocoding_unavailable', previous: $exception);
        }
        if (429 === $status) {
            throw new ApiException(JsonResponse::HTTP_TOO_MANY_REQUESTS, 'RATE_LIMIT_EXCEEDED', 'rate_limit_exceeded');
        }

        return [$status, $payload];
    }

    private function reserveCredits(int $credits): void
    {
        try {
            $this->creditRateLimiter->create('geoapify-api-key')->reserve($credits, 0.0)->wait();
        } catch (MaxWaitDurationExceededException $exception) {
            throw new ApiException(JsonResponse::HTTP_TOO_MANY_REQUESTS, 'GEOCODING_QUOTA_EXCEEDED', 'geocoding_quota_exceeded', previous: $exception);
        }
    }

    private function pollingInterval(int $batchSize): int
    {
        return match (true) {
            $batchSize <= self::SMALL_BATCH_MAX_SIZE  => self::SMALL_BATCH_POLLING_INTERVAL_SECONDS,
            $batchSize <= self::MEDIUM_BATCH_MAX_SIZE => self::MEDIUM_BATCH_POLLING_INTERVAL_SECONDS,
            default                                   => self::LARGE_BATCH_POLLING_INTERVAL_SECONDS,
        };
    }

    private function query(string $query): string
    {
        $normalized = trim($query);
        if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 200) {
            $this->invalid('q', 'text_length_invalid');
        }

        return $normalized;
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

    private function cacheKey(string $operation, string $locale, string $value): string
    {
        return 'geoapify.' . $operation . '.' . hash('sha256', "v1\0" . $locale . "\0" . mb_strtolower($value));
    }

    private function normalizedName(string $value): string
    {
        $normalized = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', mb_strtolower($value));

        return trim($normalized ?? '');
    }

    private function levenshtein(string $left, string $right): int
    {
        $leftCharacters = mb_str_split($left);
        $rightCharacters = mb_str_split($right);
        if (count($leftCharacters) > count($rightCharacters)) {
            [$leftCharacters, $rightCharacters] = [$rightCharacters, $leftCharacters];
        }
        $previous = range(0, count($leftCharacters));
        foreach ($rightCharacters as $rightIndex => $rightCharacter) {
            $current = [$rightIndex + 1];
            foreach ($leftCharacters as $leftIndex => $leftCharacter) {
                $current[] = min(
                    $current[$leftIndex] + 1,
                    $previous[$leftIndex + 1] + 1,
                    $previous[$leftIndex] + ($leftCharacter === $rightCharacter ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[array_key_last($previous)];
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'GEOCODING_QUERY_INVALID', 'geocoding_query_invalid', [$field => [$error]]);
    }
}
