<?php

namespace App\Tests\Api\Person;

use App\Tests\Api\ApiWebTestCase;

final class SurnameLocalizationApiTest extends ApiWebTestCase
{
    public function testRussianSurnameIsConvertedBetweenGenderForms(): void
    {
        $accessToken = $this->register('ru');

        $this->jsonRequest('POST', '/api/v1/surname-localization', [
            'surname'      => 'Иванов',
            'sourceGender' => 'male',
            'targetGender' => 'female',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Иванова', $this->responseString('surname'));

        $this->jsonRequest('POST', '/api/v1/surname-localization', [
            'surname'      => 'Петрова-Сидорова',
            'sourceGender' => 'female',
            'targetGender' => 'male',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Петров-Сидоров', $this->responseString('surname'));
    }

    public function testEnglishLocalizationPreservesTheSurname(): void
    {
        $accessToken = $this->register('en');

        $this->jsonRequest('POST', '/api/v1/surname-localization', [
            'surname'      => 'Ivanov',
            'sourceGender' => 'male',
            'targetGender' => 'female',
        ], $this->authorization($accessToken));

        self::assertResponseIsSuccessful();
        self::assertSame('Ivanov', $this->responseString('surname'));
    }

    public function testLocalizationValidatesItsRequestAndRequiresAuthentication(): void
    {
        $this->jsonRequest('POST', '/api/v1/surname-localization', [
            'surname'      => 'Иванов',
            'sourceGender' => 'unknown',
            'targetGender' => 'female',
        ]);

        self::assertResponseStatusCodeSame(401);

        $accessToken = $this->register('ru');
        $this->jsonRequest('POST', '/api/v1/surname-localization', [
            'surname'      => ' ',
            'sourceGender' => 'male',
            'targetGender' => 'unknown',
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
