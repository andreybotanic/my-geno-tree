<?php

namespace App\Tests\Api\Auth;

use App\Http\RefreshCookieFactory;
use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class SessionApiTest extends ApiWebTestCase
{
    public function testLoginUsesCaseSensitiveUsernameAndCaseInsensitiveEmail(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['username' => 'CaseUser']),
        );
        self::assertResponseStatusCodeSame(201);

        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'caseuser',
            'password'   => 'StrongPass1!',
        ]);
        self::assertResponseStatusCodeSame(401);

        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'FAMILY@EXAMPLE.TEST',
            'password'   => 'StrongPass1!',
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testUnknownAccountAndWrongPasswordHaveTheSameError(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );

        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'missing_user',
            'password'   => 'WrongPass1!',
        ]);
        $missingAccountResponse = $this->client->getResponse()->getContent();

        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'family_admin',
            'password'   => 'WrongPass1!',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            $missingAccountResponse,
            $this->client->getResponse()->getContent(),
        );
    }

    public function testLoginReportsFieldValidationErrors(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => '',
            'password'   => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([
            'identifier' => ['identifier_required'],
            'password'   => ['password_required'],
        ], $this->responseData()['fields']);
    }

    public function testRefreshRotatesTokensAndReusingTheOldTokenRevokesTheSession(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        $registrationAccessToken = $this->responseString('accessToken');
        $oldRefreshToken = $this->client->getCookieJar()
            ->get(
                RefreshCookieFactory::NAME,
                '/api/v1/auth',
                'localhost',
            )
            ?->getValue();
        self::assertNotNull($oldRefreshToken);

        $this->jsonRequest('POST', '/api/v1/auth/refresh');
        self::assertResponseIsSuccessful();
        $rotatedAccessToken = $this->responseString('accessToken');
        self::assertNotSame(
            $registrationAccessToken,
            $rotatedAccessToken,
        );

        $replayClient = clone $this->client;
        $replayClient->getCookieJar()->set(new Cookie(
            RefreshCookieFactory::NAME,
            $oldRefreshToken,
            path: '/api/v1/auth',
            domain: 'localhost',
            secure: true,
            httponly: true,
            samesite: 'strict',
        ));
        $replayClient->request(
            'POST',
            'https://localhost/api/v1/auth/refresh',
            server: ['HTTPS' => 'on'],
        );
        self::assertSame(401, $replayClient->getResponse()->getStatusCode());

        $this->jsonRequest('GET', '/api/v1/me', headers: [
            'Authorization' => 'Bearer ' . $rotatedAccessToken,
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRevokesOnlyTheCurrentSessionImmediately(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        $firstAccessToken = $this->responseString('accessToken');
        $firstClient = clone $this->client;

        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'family_admin',
            'password'   => 'StrongPass1!',
        ]);
        $secondAccessToken = $this->responseString('accessToken');

        $firstClient->request(
            'POST',
            'https://localhost/api/v1/auth/logout',
            server: [
                'HTTPS'              => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $firstAccessToken,
            ],
        );
        self::assertSame(204, $firstClient->getResponse()->getStatusCode());

        $this->jsonRequest('GET', '/api/v1/me', headers: [
            'Authorization' => 'Bearer ' . $firstAccessToken,
        ]);
        self::assertResponseStatusCodeSame(401);

        $this->jsonRequest('GET', '/api/v1/me', headers: [
            'Authorization' => 'Bearer ' . $secondAccessToken,
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testLogoutCanResolveTheSessionFromItsRefreshCookie(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        $accessToken = $this->responseString('accessToken');

        $this->jsonRequest('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/v1/me', headers: [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        self::assertResponseStatusCodeSame(401);

        $this->jsonRequest('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(204);
    }

    public function testRefreshRequiresItsSecureCookie(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/refresh');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            'INVALID_REFRESH_TOKEN',
            $this->responseString('code'),
        );
    }

    public function testProfileLanguageCanBeChanged(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        $accessToken = $this->responseString('accessToken');

        $this->jsonRequest('PATCH', '/api/v1/me', [
            'language' => 'ru',
        ], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('ru', $this->responseString('preferredLanguage'));
    }

    public function testBloodNetworkPresetsCanBeRenamedAndPersisted(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        $accessToken = $this->responseString('accessToken');
        $presets = [
            [
                'id'        => 'weak',
                'name'      => 'Рассеянный',
                'isDefault' => true,
                'forces'    => $this->bloodNetworkForces(8, 0),
            ],
            [
                'id'        => 'normal',
                'name'      => 'Собранный',
                'isDefault' => false,
                'forces'    => $this->bloodNetworkForces(3, 12),
            ],
        ];

        $this->jsonRequest('PATCH', '/api/v1/me/blood-network-presets', [
            'presets' => $presets,
        ], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($presets, $this->responseData()['bloodNetworkPresets']);

        $this->jsonRequest('GET', '/api/v1/me', headers: [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($presets, $this->responseData()['bloodNetworkPresets']);
    }

    public function testBloodNetworkPresetsRequireExactlyOneDefault(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $accessToken = $this->responseString('accessToken');

        $this->jsonRequest('PATCH', '/api/v1/me/blood-network-presets', [
            'presets' => [
                [
                    'id'        => 'weak',
                    'name'      => 'Слабый',
                    'isDefault' => true,
                    'forces'    => $this->bloodNetworkForces(8, 0),
                ],
                [
                    'id'        => 'normal',
                    'name'      => 'Нормальный',
                    'isDefault' => true,
                    'forces'    => $this->bloodNetworkForces(3, 12),
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(
            'blood_network_preset_default_invalid',
            $this->responseStringList('fields', 'presets'),
        );
    }

    public function testProtectedEndpointsRequireAnActiveSession(): void
    {
        $this->jsonRequest('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame([
            'code'    => 'UNAUTHORIZED',
            'message' => 'authentication_required',
            'fields'  => [],
            'details' => [],
        ], $this->responseData());
    }

    /**
     * @return array<string, float|int>
     */
    private function bloodNetworkForces(int $baseRepulsion, int $descendantSideForce): array
    {
        return [
            'averageMovementPerSecondThreshold' => 1,
            'baseRepulsion'                     => $baseRepulsion,
            'baseRepulsionDistanceExponent'     => 3,
            'descendantSideForce'               => $descendantSideForce,
            'edgeNodeCapsuleThickness'          => 100,
            'edgeNodeRepulsionStrength'         => 30,
            'personFlowHingeStrength'           => 50,
            'parentChildPathLength'             => 300,
            'unrelatedParentNodeRepulsion'      => 6,
            'kinshipRepulsionStep'              => 0,
            'parentHingeSpringStrength'         => 4,
            'parentHingeTargetAngle'            => 45,
            'parentPairSpringStrength'          => 0.2,
            'parentToFamilyLengthRatio'         => 0.58,
            'sharedParentSpringStrength'        => 2,
        ];
    }
}
