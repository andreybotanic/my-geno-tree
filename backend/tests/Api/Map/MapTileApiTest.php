<?php

namespace App\Tests\Api\Map;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class MapTileApiTest extends ApiWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $factory = static::getContainer()->get('limiter.yandex_tiles_global');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $factory->create('yandex-tiles-api-key')->reset();
    }

    public function testTileRedirectRequiresAuthenticationAndValidCoordinates(): void
    {
        $this->jsonRequest('GET', '/api/v1/map/tiles/1/0/0.png');
        self::assertResponseStatusCodeSame(401);

        $token = $this->register();
        $this->jsonRequest(
            'GET',
            '/api/v1/map/tiles/3/8/0.png',
            headers: ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testTileRequestRedirectsToASignedYandexUrl(): void
    {
        $token = $this->register();

        $this->jsonRequest(
            'GET',
            '/api/v1/map/tiles/7/73/42.png',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(302);
        $response = $this->client->getResponse();
        $location = $response->headers->get('Location');
        self::assertIsString($location);
        self::assertStringStartsWith(
            'https://tiles.api-maps.yandex.ru/v1/tiles/?apikey=',
            $location,
        );
        self::assertMatchesRegularExpression(
            '/&lang=ru_RU&x=73&y=42&z=7&l=map&scale=1&projection=web_mercator&maptype=map&signature=[A-Za-z0-9_-]+=*$/',
            $location,
        );
        self::assertStringNotContainsString('test-signing-secret', $location);
        $cacheControl = $response->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertFalse($response->headers->has('Authorization'));
    }

    public function testGlobalLimitRejectsTheTwentySixthRequestWithoutARedirect(): void
    {
        $token = $this->register();
        $factory = static::getContainer()->get('limiter.yandex_tiles_global');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create('yandex-tiles-api-key');
        $limiter->reset();
        for ($request = 1; $request <= 25; ++$request) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        $this->jsonRequest(
            'GET',
            '/api/v1/map/tiles/7/73/42.png',
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(429);
        $response = $this->client->getResponse();
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertFalse($response->headers->has('Location'));
        self::assertSame('RATE_LIMIT_EXCEEDED', $this->responseString('code'));
    }

    public function testConfiguredUserBucketAllowsOneHundredEightyRequestsPerMinute(): void
    {
        $factory = static::getContainer()->get('limiter.yandex_tiles_user');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create('configured-user-limit-test');
        $limiter->reset();

        for ($request = 1; $request <= 180; ++$request) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        self::assertFalse($limiter->consume()->isAccepted());
    }

    private function register(): string
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }
}
