<?php

namespace App\Tests\Unit\Application\Map;

use App\Api\ApiException;
use App\Application\Map\NominatimGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class NominatimGeocoderTest extends TestCase
{
    public function testSearchRequestsAtMostFiveResultsAndCachesTheResponse(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestCount): MockResponse {
            ++$requestCount;
            self::assertSame('GET', $method);
            self::assertStringContainsString('/search?', $url);
            self::assertStringContainsString('limit=5', $url);
            self::assertStringContainsString('featureType=settlement', $url);
            self::assertStringContainsString('accept-language=ru', $url);

            return new MockResponse(json_encode([
                ['display_name' => 'Novosibirsk', 'lat' => '55.0302', 'lon' => '82.9204'],
                ['display_name' => 'Invalid result'],
            ], JSON_THROW_ON_ERROR));
        }, 'https://nominatim.example.test');
        $geocoder = new NominatimGeocoder(
            $client,
            new ArrayAdapter(),
            $this->acceptingLimiterFactory(1),
        );

        $first = $geocoder->search('  Novosibirsk  ', 'ru');
        $second = $geocoder->search('Novosibirsk', 'ru');

        self::assertSame($first, $second);
        self::assertSame(1, $requestCount);
        self::assertSame([[
            'name'        => 'Novosibirsk',
            'coordinates' => ['latitude' => 55.0302, 'longitude' => 82.9204],
        ]], $first);
    }

    public function testReverseGeocodingReturnsAResolvedPlace(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('/reverse?', $url);
            self::assertStringContainsString('lat=55.0302', $url);
            self::assertStringContainsString('lon=82.9204', $url);

            return new MockResponse(json_encode([
                'display_name' => 'Novosibirsk, Russia',
                'lat'          => '55.0302',
                'lon'          => '82.9204',
            ], JSON_THROW_ON_ERROR));
        }, 'https://nominatim.example.test');
        $geocoder = new NominatimGeocoder(
            $client,
            new ArrayAdapter(),
            $this->acceptingLimiterFactory(1),
        );

        self::assertSame([
            'name'        => 'Novosibirsk, Russia',
            'coordinates' => ['latitude' => 55.0302, 'longitude' => 82.9204],
        ], $geocoder->reverse(55.0302, 82.9204, 'en'));
    }

    public function testInvalidInputIsRejectedWithoutAnUpstreamRequest(): void
    {
        $geocoder = new NominatimGeocoder(
            new MockHttpClient([], 'https://nominatim.example.test'),
            new ArrayAdapter(),
            $this->acceptingLimiterFactory(0),
        );

        try {
            $geocoder->search('x', 'en');
            self::fail('Expected an invalid search query.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->getStatus());
            self::assertSame('GEOCODING_QUERY_INVALID', $exception->getErrorCode());
        }

        $this->expectException(ApiException::class);
        $geocoder->reverse(91, 0, 'en');
    }

    private function acceptingLimiterFactory(int $expectedReservations): RateLimiterFactoryInterface
    {
        $reservation = new Reservation(microtime(true) - 1, new \Symfony\Component\RateLimiter\RateLimit(
            1,
            new \DateTimeImmutable('2000-01-01T00:00:00+00:00'),
            true,
            1,
        ));
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter
            ->expects($this->exactly($expectedReservations))
            ->method('reserve')
            ->with(1, 3.0)
            ->willReturn($reservation);
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory
            ->expects($this->exactly($expectedReservations))
            ->method('create')
            ->with('nominatim-public-api')
            ->willReturn($limiter);

        return $factory;
    }
}
