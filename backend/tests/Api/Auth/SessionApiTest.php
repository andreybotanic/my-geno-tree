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
        $registration = $this->responseData();
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
        $rotated = $this->responseData();
        self::assertNotSame(
            $registration['accessToken'],
            $rotated['accessToken'],
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
            'Authorization' => 'Bearer ' . $rotated['accessToken'],
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
        $firstAccessToken = $this->responseData()['accessToken'];
        $firstClient = clone $this->client;

        $this->jsonRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'family_admin',
            'password'   => 'StrongPass1!',
        ]);
        $secondAccessToken = $this->responseData()['accessToken'];

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
        $accessToken = $this->responseData()['accessToken'];

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
            $this->responseData()['code'],
        );
    }

    public function testProfileLanguageCanBeChanged(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        $accessToken = $this->responseData()['accessToken'];

        $this->jsonRequest('PATCH', '/api/v1/me', [
            'language' => 'ru',
        ], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('ru', $this->responseData()['preferredLanguage']);
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
}
