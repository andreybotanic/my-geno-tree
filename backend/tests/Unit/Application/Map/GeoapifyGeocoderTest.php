<?php

namespace App\Tests\Unit\Application\Map;

use App\Api\ApiException;
use App\Application\Map\GeoapifyGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class GeoapifyGeocoderTest extends TestCase
{
    public function testAutocompleteUsesSettlementParametersAndCachesTheResponse(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            ++$requests;
            self::assertSame('GET', $method);
            self::assertStringContainsString('/geocode/autocomplete?', $url);
            self::assertStringContainsString('text=Novosibirsk', $url);
            self::assertStringContainsString('type=city', $url);
            self::assertStringContainsString('lang=ru', $url);
            self::assertStringContainsString('limit=5', $url);
            self::assertStringContainsString('bias=countrycode:none', $url);

            return $this->response(['results' => [[
                'formatted' => 'Novosibirsk, Russia', 'lat' => 55.0302, 'lon' => 82.9204,
            ]]]);
        }, 'https://geoapify.example.test/v1');
        $geocoder = $this->geocoder($client);

        $first = $geocoder->autocomplete('  Novosibirsk  ', 'ru');
        $second = $geocoder->autocomplete('Novosibirsk', 'ru');

        self::assertSame([[
            'name'        => 'Novosibirsk, Russia',
            'coordinates' => ['latitude' => 55.0302, 'longitude' => 82.9204],
        ]], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $requests);
    }

    public function testReverseUsesSettlementParameters(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('/geocode/reverse?', $url);
            self::assertStringContainsString('lat=55.0302', $url);
            self::assertStringContainsString('lon=82.9204', $url);
            self::assertStringContainsString('type=city', $url);
            self::assertStringContainsString('limit=1', $url);

            return $this->response(['results' => [[
                'city' => 'Novosibirsk', 'lat' => 55.0302, 'lon' => 82.9204,
            ]]]);
        }, 'https://geoapify.example.test/v1');

        self::assertSame([
            'name'        => 'Novosibirsk',
            'coordinates' => ['latitude' => 55.0302, 'longitude' => 82.9204],
        ], $this->geocoder($client)->reverse(55.0302, 82.9204, 'en'));
    }

    public function testBatchPollsEveryFifteenSecondsAndChargesQuarterCredits(): void
    {
        $calls = [];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$calls): MockResponse {
                $calls[] = [$method, $url, $options];

                return match (count($calls)) {
                    1 => $this->response(['id' => 'job-1'], 202),
                    2 => $this->response(['id' => 'job-1', 'status' => 'pending'], 202),
                    3 => $this->response([[
                        'query'     => ['text' => 'п. Лебединый, Якутия'],
                        'name'      => 'Лебединый',
                        'city'      => 'Лебединый',
                        'formatted' => 'Лебединый, Республика Саха, Россия',
                        'lat'       => 58.0,
                        'lon'       => 125.0,
                    ]]),
                    default => throw new \LogicException('Unexpected Geoapify request.'),
                };
            },
            'https://geoapify.example.test/v1',
        );
        $requestReservations = [];
        $creditReservations = [];
        $clock = new MockClock('2026-01-01T00:00:00+00:00');
        $geocoder = $this->geocoder(
            $client,
            $this->recordingFactory($requestReservations),
            $this->recordingFactory($creditReservations),
            $clock,
        );

        self::assertSame([
            'п. Лебединый, Якутия' => [
                'name'        => 'Лебединый',
                'coordinates' => ['latitude' => 58.0, 'longitude' => 125.0],
            ],
        ], $geocoder->batchBestMatches(['п. Лебединый, Якутия'], 'ru'));
        self::assertSame(['POST', 'GET', 'GET'], array_column($calls, 0));
        self::assertSame(['п. Лебединый, Якутия'], json_decode($calls[0][2]['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('type=city', $calls[0][1]);
        self::assertStringContainsString('id=job-1', $calls[1][1]);
        self::assertSame([1, 1, 1], $requestReservations);
        self::assertSame([2, 4, 4, 4], $creditReservations);
        self::assertSame('2026-01-01T00:00:30+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testBatchPollingIntervalGrowsWithTheBatchSize(): void
    {
        foreach ([100 => 15, 101 => 30, 301 => 60] as $batchSize => $expectedInterval) {
            $queries = array_map(static fn (int $number): string => 'Town ' . $number, range(1, $batchSize));
            $clock = new MockClock('2026-01-01T00:00:00+00:00');
            $client = new MockHttpClient([
                $this->response(['id' => 'job-' . $batchSize], 202),
                $this->response(array_map(static fn (string $query): array => [
                    'query' => ['text' => $query], 'city' => $query, 'lat' => 1, 'lon' => 2,
                ], $queries)),
            ], 'https://geoapify.example.test/v1/');

            $this->geocoder($client, clock: $clock)->batchBestMatches($queries, 'en');

            self::assertSame(
                (new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))
                    ->modify('+' . $expectedInterval . ' seconds')
                    ->format(DATE_ATOM),
                $clock->now()->format(DATE_ATOM),
            );
        }
    }

    public function testBatchSplitsAtOneThousandAndPreservesPartialResultsWhenCreditsRunOut(): void
    {
        $firstBatch = array_map(static fn (int $number): string => 'Town ' . $number, range(1, 1000));
        $queries = [...$firstBatch, 'Town 1001'];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use ($firstBatch): MockResponse {
                static $call = 0;
                ++$call;
                if (1 === $call) {
                    $sent = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertIsArray($sent);
                    self::assertCount(1000, $sent);

                    return $this->response(['id' => 'first'], 202);
                }
                if (2 === $call) {
                    return $this->response(array_map(static fn (string $query): array => [
                        'query' => ['text' => $query], 'city' => $query, 'lat' => 1, 'lon' => 2,
                    ], $firstBatch));
                }

                throw new \LogicException('The second batch must not be sent after its item budget is refused.');
            },
            'https://geoapify.example.test/v1',
        );
        $credits = [];
        $creditAttempt = 0;
        $ignored = [];
        $geocoder = $this->geocoder(
            $client,
            $this->recordingFactory($ignored),
            $this->recordingFactory($credits, static function () use (&$creditAttempt): bool {
                ++$creditAttempt;

                return $creditAttempt < 4;
            }),
            new MockClock('2026-01-01T00:00:00+00:00'),
        );

        $matches = $geocoder->batchBestMatches($queries, 'en');

        self::assertCount(1000, $matches);
        self::assertArrayNotHasKey('Town 1001', $matches);
        self::assertSame([2000, 4, 4, 2], $credits);
    }

    public function testBatchUsesUnicodeLevenshteinAndKeepsTheFirstNameOnAnEqualDistance(): void
    {
        $client = new MockHttpClient([
            $this->response(['id' => 'job'], 202),
            $this->response([[
                'query'     => ['text' => 'п. Лебединый, Якутия'],
                'name'      => 'Лебединый Якутия',
                'city'      => 'Лебединый, Якутия',
                'formatted' => 'Республика Саха, Россия',
                'lat'       => 1,
                'lon'       => 2,
            ]]),
        ], 'https://geoapify.example.test/v1');

        $match = $this->geocoder($client, clock: new MockClock())->batchBestMatches(['п. Лебединый, Якутия'], 'ru');

        self::assertSame('Лебединый Якутия', $match['п. Лебединый, Якутия']['name']);
    }

    public function testInvalidInputAndUnavailableServiceDoNotReturnAPlace(): void
    {
        $geocoder = $this->geocoder(new MockHttpClient([], 'https://geoapify.example.test/v1'));
        try {
            $geocoder->autocomplete('x', 'en');
            self::fail('Expected an invalid query.');
        } catch (ApiException $exception) {
            self::assertSame('GEOCODING_QUERY_INVALID', $exception->getErrorCode());
        }

        $unavailable = $this->geocoder(
            new MockHttpClient($this->response([], 503), 'https://geoapify.example.test/v1'),
        );
        self::assertSame([], $unavailable->batchBestMatches(['Novosibirsk'], 'en'));
    }

    private function geocoder(
        MockHttpClient $client,
        ?RateLimiterFactoryInterface $requests = null,
        ?RateLimiterFactoryInterface $credits = null,
        ?MockClock $clock = null,
    ): GeoapifyGeocoder {
        $ignoredRequests = [];
        $ignoredCredits = [];

        return new GeoapifyGeocoder(
            $client,
            new ArrayAdapter(),
            $requests ?? $this->recordingFactory($ignoredRequests),
            $credits ?? $this->recordingFactory($ignoredCredits),
            'geoapify-test-api-key',
            $clock ?? new MockClock(),
        );
    }

    /** @param list<int> $reservations */
    private function recordingFactory(array &$reservations, ?callable $accept = null): RateLimiterFactoryInterface
    {
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('reserve')->willReturnCallback(
            function (int $tokens, float $maxWaitDuration) use (&$reservations, $accept): Reservation {
                $reservations[] = $tokens;
                if (null !== $accept && !$accept($tokens)) {
                    throw new \Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException('Budget exhausted.', new RateLimit(0, new \DateTimeImmutable('2000-01-01T00:00:00+00:00'), false, 12000));
                }

                return new Reservation(microtime(true) - 1, new RateLimit(
                    12000,
                    new \DateTimeImmutable('2000-01-01T00:00:00+00:00'),
                    true,
                    12000,
                ));
            },
        );
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    /** @param array<mixed> $body */
    private function response(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
