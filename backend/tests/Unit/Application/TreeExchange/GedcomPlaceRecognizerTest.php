<?php

namespace App\Tests\Unit\Application\TreeExchange;

use App\Application\Map\GeoapifyGeocoder;
use App\Application\TreeExchange\GedcomParser;
use App\Application\TreeExchange\GedcomPlaceRecognizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class GedcomPlaceRecognizerTest extends TestCase
{
    public function testRecognizesAllDistinctTextOnlyPlacesInOneBatchAndLeavesCoordinatesUntouched(): void
    {
        $document = (new GedcomParser())->parse(<<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Anna /Petrova/
1 BIRT
2 PLAC Novosibirsk
1 RESI
2 PLAC Tomsk
1 EVEN
2 TYPE Visit
2 PLAC Tomsk
1 BURI
2 PLAC Moscow
3 MAP
4 LATI N55.7558
4 LONG E37.6173
0 TRLR
GEDCOM);
        $calls = 0;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$calls): MockResponse {
                ++$calls;
                if (1 === $calls) {
                    self::assertSame('POST', $method);
                    self::assertSame(
                        ['Novosibirsk', 'Tomsk'],
                        json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR),
                    );

                    return $this->response(['id' => 'job'], 202);
                }
                self::assertSame('GET', $method);

                return $this->response(
                    [
                        [
                            'query' => ['text' => 'Novosibirsk'],
                            'city'  => 'Novosibirsk, Novosibirsk Oblast, Russia',
                            'lat'   => 55.0302,
                            'lon'   => 82.9204,
                        ],
                        [
                            'query' => ['text' => 'Tomsk'],
                            'city'  => 'Tomsk, Tomsk Oblast, Russia',
                            'lat'   => 56.4977,
                            'lon'   => 84.9744,
                        ],
                    ],
                );
            },
            'https://geoapify.example.test/v1',
        );
        $recognizer = new GedcomPlaceRecognizer($this->geocoder($client));

        $recognition = $recognizer->recognize($document, 'en');

        self::assertSame([
            'Novosibirsk' => ['name' => 'Novosibirsk', 'latitude' => 55.0302, 'longitude' => 82.9204],
            'Tomsk'       => ['name' => 'Tomsk', 'latitude' => 56.4977, 'longitude' => 84.9744],
        ], $recognition->places);
        self::assertSame(0, $recognition->unrecognizedPlaceCount);
        self::assertSame(2, $calls);
    }

    public function testKeepsUnrecognizedPlacesAsTextWhenGeoapifyIsUnavailable(): void
    {
        $document = (new GedcomParser())->parse(<<<'GEDCOM'
0 HEAD
1 GEDC
2 VERS 5.5.1
1 CHAR UTF-8
0 @I1@ INDI
1 NAME Anna /Petrova/
1 BIRT
2 PLAC Novosibirsk
0 TRLR
GEDCOM);
        $recognition = (new GedcomPlaceRecognizer($this->geocoder(
            new MockHttpClient($this->response([], 503), 'https://geoapify.example.test/v1'),
        )))->recognize($document, 'en');

        self::assertSame([], $recognition->places);
        self::assertSame(1, $recognition->unrecognizedPlaceCount);
    }

    private function geocoder(MockHttpClient $client): GeoapifyGeocoder
    {
        return new GeoapifyGeocoder(
            $client,
            new ArrayAdapter(),
            $this->factory(),
            $this->factory(),
            'geoapify-test-api-key',
            new MockClock('2026-01-01T00:00:00+00:00'),
        );
    }

    private function factory(): RateLimiterFactoryInterface
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('reserve')->willReturn(new Reservation(microtime(true) - 1, new RateLimit(
            12000,
            new \DateTimeImmutable('2000-01-01T00:00:00+00:00'),
            true,
            12000,
        )));
        $factory->method('create')->willReturn($limiter);

        return $factory;
    }

    /** @param array<mixed> $body */
    private function response(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
