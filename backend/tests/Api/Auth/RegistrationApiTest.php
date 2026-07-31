<?php

namespace App\Tests\Api\Auth;

use App\Http\RefreshCookieFactory;
use App\Tests\Api\ApiWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class RegistrationApiTest extends ApiWebTestCase
{
    public function testRegistrationCreatesAnAuthenticatedUuidV7User(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['language' => 'ru']),
        );

        self::assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        self::assertArrayHasKey('accessToken', $data);
        self::assertIsString($data['accessToken']);
        self::assertSame('ru', $data['user']['preferredLanguage']);

        $userId = $data['user']['id'];
        self::assertIsString($userId);
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($userId));

        $cookie = $this->client->getCookieJar()->get(
            RefreshCookieFactory::NAME,
            '/api/v1/auth',
            'localhost',
        );
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/api/v1/auth', $cookie->getPath());
        self::assertSame('strict', strtolower((string)$cookie->getSameSite()));

        $this->jsonRequest('GET', '/api/v1/me', headers: [
            'Authorization' => 'Bearer ' . $data['accessToken'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($userId, $this->responseData()['id']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidRegistrationProvider')]
    public function testRegistrationRejectsInvalidDomainFields(
        array $overrides,
        string $field,
        string $error,
    ): void {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration($overrides),
        );

        self::assertResponseStatusCodeSame(422);
        $data = $this->responseData();
        self::assertSame('VALIDATION_FAILED', $data['code']);
        self::assertContains($error, $data['fields'][$field]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidRegistrationProvider(): iterable
    {
        yield 'short username' => [
            ['username' => 'abc'],
            'username',
            'username_invalid',
        ];
        yield 'username whitespace' => [
            ['username' => 'bad user'],
            'username',
            'username_invalid',
        ];
        yield 'bad email' => [
            ['email' => 'not-an-email'],
            'email',
            'email_invalid',
        ];
        yield 'email over storage limit' => [
            ['email' => str_repeat('a', 243) . '@example.test'],
            'email',
            'email_invalid',
        ];
        yield 'password whitespace' => [
            ['password' => 'Strong pass1!'],
            'password',
            'password_invalid_format',
        ];
        yield 'password categories' => [
            ['password' => 'lowercase'],
            'password',
            'password_not_complex_enough',
        ];
    }

    public function testEmailIsTrimmedAndNormalizedForUniqueness(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration([
                'email' => '  Family@Example.Test  ',
            ]),
        );
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            'family@example.test',
            $this->responseData()['user']['email'],
        );

        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration([
                'username' => 'another_user',
                'email'    => 'FAMILY@example.test',
            ]),
        );
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            ['email_not_unique'],
            $this->responseData()['fields']['email'],
        );
    }

    public function testUsernameUniquenessRemainsCaseSensitive(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['username' => 'CaseUser']),
        );
        self::assertResponseStatusCodeSame(201);

        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration([
                'username' => 'caseuser',
                'email'    => 'other@example.test',
            ]),
        );
        self::assertResponseStatusCodeSame(201);
    }

    public function testMalformedAndUnexpectedJsonUseTheUnifiedErrorShape(): void
    {
        $this->client->request(
            'POST',
            'https://localhost/api/v1/auth/register',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTPS'        => 'on',
            ],
            content: '{',
        );
        self::assertResponseStatusCodeSame(400);
        self::assertSame([
            'code',
            'message',
            'fields',
            'details',
        ], array_keys($this->responseData()));

        $payload = $this->validRegistration();
        $payload['unexpected'] = true;
        $this->jsonRequest('POST', '/api/v1/auth/register', $payload);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            'INVALID_REQUEST_BODY',
            $this->responseData()['code'],
        );
    }

    public function testJsonContentTypeIsRequired(): void
    {
        $this->client->request(
            'POST',
            'https://localhost/api/v1/auth/register',
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTPS' => 'on'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(415);
        self::assertSame(
            'UNSUPPORTED_MEDIA_TYPE',
            $this->responseData()['code'],
        );
    }
}
