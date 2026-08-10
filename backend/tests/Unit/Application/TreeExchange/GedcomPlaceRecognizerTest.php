<?php

namespace App\Tests\Unit\Application\TreeExchange;

use App\Application\Map\NominatimGeocoder;
use App\Application\TreeExchange\Gedcom55Parser;
use App\Application\TreeExchange\GedcomPlaceRecognizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class GedcomPlaceRecognizerTest extends TestCase
{
    public function testRecognizesEachDistinctTextOnlyPlaceAndLeavesResolvedPlacesUntouched(): void
    {
        $document = (new Gedcom55Parser())->parse(<<<'GEDCOM'
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
        $requestCount = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestCount): MockResponse {
            ++$requestCount;
            self::assertSame('GET', $method);

            return new MockResponse(json_encode(
                str_contains($url, 'Tomsk')
                    ? [['display_name' => 'Tomsk, Russia', 'lat' => '56.4977', 'lon' => '84.9744']]
                    : [['display_name' => 'Novosibirsk, Russia', 'lat' => '55.0302', 'lon' => '82.9204']],
                JSON_THROW_ON_ERROR,
            ));
        }, 'https://nominatim.example.test');
        $recognizer = new GedcomPlaceRecognizer(new NominatimGeocoder(
            $client,
            new ArrayAdapter(),
            $this->acceptingLimiterFactory(2),
        ));

        self::assertSame([
            'Novosibirsk' => ['name' => 'Novosibirsk, Russia', 'latitude' => 55.0302, 'longitude' => 82.9204],
            'Tomsk'       => ['name' => 'Tomsk, Russia', 'latitude' => 56.4977, 'longitude' => 84.9744],
        ], $recognizer->recognize($document, 'en'));
        self::assertSame(2, $requestCount);
    }

    public function testKeepsImportPossibleWhenNominatimIsUnavailable(): void
    {
        $document = (new Gedcom55Parser())->parse(<<<'GEDCOM'
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
        $recognizer = new GedcomPlaceRecognizer(new NominatimGeocoder(
            new MockHttpClient(new MockResponse('', ['http_code' => 503]), 'https://nominatim.example.test'),
            new ArrayAdapter(),
            $this->acceptingLimiterFactory(1),
        ));

        self::assertSame([], $recognizer->recognize($document, 'en'));
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
