<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Entity\Person;
use App\Entity\Value\DatePrecision;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class PersonDataPolicy
{
    private const array PERSON_FIELDS = [
        'birthSurname',
        'givenName',
        'patronymic',
        'gender',
        'birthDate',
        'deathDate',
    ];

    public function create(mixed $value): PersonData
    {
        $payload = $this->object($value, 'person');
        $this->assertExactKeys($payload, self::PERSON_FIELDS, 'person');

        return $this->build(
            $this->knownText($payload['birthSurname'], 'birthSurname'),
            $this->knownText($payload['givenName'], 'givenName'),
            $this->knownText($payload['patronymic'], 'patronymic'),
            $this->gender($payload['gender']),
            $this->date($payload['birthDate'], 'birthDate'),
            $this->optionalDate($payload['deathDate'], 'deathDate'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patch(Person $person, array $payload): PersonData
    {
        if ([] === $payload) {
            $this->fail('person', 'at_least_one_field_required');
        }
        $this->assertAllowedKeys($payload, self::PERSON_FIELDS, 'person');

        return $this->build(
            array_key_exists('birthSurname', $payload)
                ? $this->knownText($payload['birthSurname'], 'birthSurname')
                : $person->getBirthSurname(),
            array_key_exists('givenName', $payload)
                ? $this->knownText($payload['givenName'], 'givenName')
                : $person->getGivenName(),
            array_key_exists('patronymic', $payload)
                ? $this->knownText($payload['patronymic'], 'patronymic')
                : $person->getPatronymic(),
            array_key_exists('gender', $payload)
                ? $this->gender($payload['gender'])
                : $person->getGender(),
            array_key_exists('birthDate', $payload)
                ? $this->date($payload['birthDate'], 'birthDate')
                : $person->getBirthDate(),
            array_key_exists('deathDate', $payload)
                ? $this->optionalDate($payload['deathDate'], 'deathDate')
                : $person->getDeathDate(),
        );
    }

    public function coordinates(mixed $value): Coordinates
    {
        $payload = $this->object($value, 'coordinates');
        $this->assertExactKeys($payload, ['x', 'y'], 'coordinates');
        foreach (['x', 'y'] as $field) {
            if (!is_int($payload[$field]) && !is_float($payload[$field])) {
                $this->fail('coordinates.' . $field, 'finite_number_required');
            }
            if (!is_finite((float)$payload[$field])) {
                $this->fail('coordinates.' . $field, 'finite_number_required');
            }
        }

        return new Coordinates(
            (float)$payload['x'],
            (float)$payload['y'],
        );
    }

    public function dateValue(
        mixed $value,
        string $field,
    ): GenealogicalDate {
        return $this->date($value, $field);
    }

    public function optionalDateValue(
        mixed $value,
        string $field,
    ): ?GenealogicalDate {
        return $this->optionalDate($value, $field);
    }

    public function knownTextValue(mixed $value, string $field): KnownText
    {
        return $this->knownText($value, $field);
    }

    private function build(
        KnownText $birthSurname,
        KnownText $givenName,
        KnownText $patronymic,
        Gender $gender,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
    ): PersonData {
        if (null !== $deathDate) {
            $birthEarliest = $birthDate->earliest();
            $deathLatest = $deathDate->latest();
            if (
                null !== $birthEarliest
                && null !== $deathLatest
                && $deathLatest < $birthEarliest
            ) {
                $this->fail('deathDate', 'death_before_birth');
            }
        }

        return new PersonData(
            $birthSurname,
            $givenName,
            $patronymic,
            $gender,
            $birthDate,
            $deathDate,
        );
    }

    private function knownText(mixed $value, string $field): KnownText
    {
        $payload = $this->object($value, $field);
        if ('unknown' === ($payload['state'] ?? null)) {
            $this->assertExactKeys($payload, ['state'], $field);

            return KnownText::unknown();
        }
        if ('known' !== ($payload['state'] ?? null)) {
            $this->fail($field, 'known_or_unknown_required');
        }
        $this->assertExactKeys($payload, ['state', 'value'], $field);
        if (!is_string($payload['value'])) {
            $this->fail($field, 'text_value_required');
        }
        $normalized = trim($payload['value']);
        if ('' === $normalized || mb_strlen($normalized) > 100) {
            $this->fail($field, 'text_length_invalid');
        }

        return KnownText::known($normalized);
    }

    private function gender(mixed $value): Gender
    {
        if (!is_string($value)) {
            $this->fail('gender', 'gender_invalid');
        }
        $gender = Gender::tryFrom($value);
        if (null === $gender) {
            $this->fail('gender', 'gender_invalid');
        }

        return $gender;
    }

    private function optionalDate(
        mixed $value,
        string $field,
    ): ?GenealogicalDate {
        return null === $value ? null : $this->date($value, $field);
    }

    private function date(mixed $value, string $field): GenealogicalDate
    {
        $payload = $this->object($value, $field);
        if ('unknown' === ($payload['state'] ?? null)) {
            $this->assertExactKeys($payload, ['state'], $field);

            return GenealogicalDate::unknown();
        }
        if ('known' !== ($payload['state'] ?? null)) {
            $this->fail($field, 'known_or_unknown_required');
        }
        if (!is_string($payload['precision'] ?? null)) {
            $this->fail($field . '.precision', 'date_precision_invalid');
        }
        $precision = DatePrecision::tryFrom($payload['precision']);
        if (null === $precision) {
            $this->fail($field . '.precision', 'date_precision_invalid');
        }
        $keys = match ($precision) {
            DatePrecision::YEAR => [
                'state',
                'precision',
                'year',
                'approximate',
            ],
            DatePrecision::MONTH => [
                'state',
                'precision',
                'year',
                'month',
                'approximate',
            ],
            DatePrecision::DAY => [
                'state',
                'precision',
                'year',
                'month',
                'day',
                'approximate',
            ],
        };
        $this->assertExactKeys($payload, $keys, $field);
        if (
            !is_int($payload['year'])
            || $payload['year'] < 1582
            || $payload['year'] > 9999
        ) {
            $this->fail($field . '.year', 'date_year_invalid');
        }
        if (!is_bool($payload['approximate'])) {
            $this->fail($field . '.approximate', 'boolean_required');
        }
        if (
            DatePrecision::YEAR !== $precision
            && $payload['approximate']
        ) {
            $this->fail($field . '.approximate', 'approximate_year_only');
        }

        $month = $payload['month'] ?? null;
        $day = $payload['day'] ?? null;
        if (
            DatePrecision::YEAR !== $precision
            && (!is_int($month) || $month < 1 || $month > 12)
        ) {
            $this->fail($field . '.month', 'date_month_invalid');
        }
        if (
            DatePrecision::DAY === $precision
            && (
                !is_int($day)
                || !checkdate($month, $day, $payload['year'])
            )
        ) {
            $this->fail($field . '.day', 'date_day_invalid');
        }

        return GenealogicalDate::known(
            $precision,
            $payload['year'],
            $month,
            $day,
            $payload['approximate'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->fail($field, 'object_required');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $expected
     */
    private function assertExactKeys(
        array $payload,
        array $expected,
        string $field,
    ): void {
        $this->assertAllowedKeys($payload, $expected, $field);
        foreach ($expected as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->fail($field . '.' . $key, 'field_required');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowed
     */
    private function assertAllowedKeys(
        array $payload,
        array $allowed,
        string $field,
    ): void {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->fail($field . '.' . $key, 'field_not_allowed');
            }
        }
    }

    private function fail(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'PERSON_DATA_INVALID', 'person_data_invalid', [$field => [$error]]);
    }
}
