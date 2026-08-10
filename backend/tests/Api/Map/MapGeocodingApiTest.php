<?php

namespace App\Tests\Api\Map;

use App\Tests\Api\ApiWebTestCase;

final class MapGeocodingApiTest extends ApiWebTestCase
{
    public function testSearchRequiresAuthentication(): void
    {
        $this->jsonRequest('GET', '/api/v1/map/geocoding/search?q=Novosibirsk');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidSearchAndCoordinatesAreRejectedWithoutCallingNominatim(): void
    {
        $token = $this->register();

        $this->jsonRequest(
            'GET',
            '/api/v1/map/geocoding/search?q=x',
            headers: ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('GEOCODING_QUERY_INVALID', $this->responseString('code'));

        $this->jsonRequest(
            'GET',
            '/api/v1/map/geocoding/reverse?latitude=91&longitude=0',
            headers: ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('GEOCODING_QUERY_INVALID', $this->responseString('code'));
    }

    private function register(): string
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }
}
