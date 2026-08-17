<?php

namespace App\Tests\Api\Person;

use App\Tests\Api\ApiWebTestCase;

final class PatronymicLocalizationApiTest extends ApiWebTestCase
{
    public function testRussianPatronymicIsDeterminedForBothChildGenders(): void
    {
        $accessToken = $this->register('ru');

        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => 'Алексей',
            'childGender'     => 'male',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Алексеевич', $this->responseString('patronymic'));

        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => 'Илья',
            'childGender'     => 'female',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Ильинична', $this->responseString('patronymic'));

        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => 'Иван',
            'childGender'     => 'male',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Иванович', $this->responseString('patronymic'));

        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => 'Андрей',
            'childGender'     => 'female',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Андреевна', $this->responseString('patronymic'));
    }

    public function testUnsupportedLocaleReturnsNullAndRequestIsValidated(): void
    {
        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => 'Alex',
            'childGender'     => 'male',
        ]);
        self::assertResponseStatusCodeSame(401);

        $accessToken = $this->register('en');

        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => 'Alex',
            'childGender'     => 'male',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertNull($this->responseData()['patronymic']);

        $this->jsonRequest('POST', '/api/v1/patronymic-localization', [
            'fatherGivenName' => ' ',
            'childGender'     => 'unknown',
        ], $this->authorization($accessToken));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_FAILED', $this->responseString('code'));
    }

    private function register(string $language): string
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['language' => $language]),
        );

        return $this->responseString('accessToken');
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorization(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }
}
