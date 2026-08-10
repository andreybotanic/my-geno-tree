<?php

namespace App\Application\Person;

use App\Api\ApiException;
use App\Application\Event\EventPlaceData;
use App\Application\Event\EventPlaceDataPolicy;
use App\Entity\Person;
use App\Entity\Value\DateKind;
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

    public function __construct(private EventPlaceDataPolicy $places)
    {
    }

    public function create(mixed $value): PersonData
    {
        $payload = $this->object($value, 'person');
        $this->assertAllowedKeys(
            $payload,
            [...self::PERSON_FIELDS, 'birthPlace', 'deathPlace'],
            'person',
        );
        foreach (self::PERSON_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                $this->fail('person.' . $field, 'field_required');
            }
        }

        $deathDate = $this->optionalDate($payload['deathDate'], 'deathDate');
        $deathPlace = $this->optionalPlace($payload['deathPlace'] ?? null, 'deathPlace');
        if (null === $deathDate && null !== $deathPlace) {
            $this->fail('deathPlace', 'death_place_requires_death');
        }

        return $this->build(
            $this->knownText($payload['birthSurname'], 'birthSurname'),
            $this->knownText($payload['givenName'], 'givenName'),
            $this->knownText($payload['patronymic'], 'patronymic'),
            $this->gender($payload['gender']),
            $this->date($payload['birthDate'], 'birthDate'),
            $deathDate,
            $this->optionalPlace($payload['birthPlace'] ?? null, 'birthPlace'),
            $deathPlace,
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
        ?EventPlaceData $birthPlace = null,
        ?EventPlaceData $deathPlace = null,
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
            $birthPlace,
            $deathPlace,
        );
    }

    private function optionalPlace(mixed $value, string $field): ?EventPlaceData
    {
        return null === $value ? null : $this->places->resolved($value, $field);
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
        if (!is_string($payload['kind'] ?? null)) {
            $this->fail($field . '.kind', 'date_kind_invalid');
        }
        $kind = DateKind::tryFrom($payload['kind']);
        if (null === $kind) {
            $this->fail($field . '.kind', 'date_kind_invalid');
        }
        $keys = ['state', 'kind', 'year', 'month', 'day'];
        if (DateKind::BETWEEN === $kind) {
            $keys[] = 'end';
        }
        $this->assertAllowedKeys($payload, $keys, $field);
        foreach (['state', 'kind', 'year'] as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->fail($field . '.' . $key, 'field_required');
            }
        }
        [$year, $month, $day] = $this->dateParts($payload, $field);
        $endYear = $endMonth = $endDay = null;
        if (DateKind::BETWEEN === $kind) {
            $end = $this->object($payload['end'], $field . '.end');
            $this->assertAllowedKeys($end, ['year', 'month', 'day'], $field . '.end');
            if (!array_key_exists('year', $end)) {
                $this->fail($field . '.end.year', 'field_required');
            }
            [$endYear, $endMonth, $endDay] = $this->dateParts($end, $field . '.end');
            $start = GenealogicalDate::known(DateKind::EXACT, $year, $month, $day);
            $finish = GenealogicalDate::known(DateKind::EXACT, $endYear, $endMonth, $endDay);
            if ($start->earliest() > $finish->latest()) {
                $this->fail($field . '.end', 'date_range_invalid');
            }
        }

        return GenealogicalDate::known(
            $kind,
            $year,
            $month,
            $day,
            $endYear,
            $endMonth,
            $endDay,
        );
    }

    /** @param array<string, mixed> $payload
     * @return array{int, int|null, int|null}
     */
    private function dateParts(array $payload, string $field): array
    {
        if (!is_int($payload['year'] ?? null) || $payload['year'] < 1582 || $payload['year'] > 9999) {
            $this->fail($field . '.year', 'date_year_invalid');
        }
        $month = $payload['month'] ?? null;
        if (null !== $month && (!is_int($month) || $month < 1 || $month > 12)) {
            $this->fail($field . '.month', 'date_month_invalid');
        }
        $day = $payload['day'] ?? null;
        if (null !== $day && (!is_int($month) || !is_int($day) || !checkdate($month, $day, $payload['year']))) {
            $this->fail($field . '.day', 'date_day_invalid');
        }

        return [$payload['year'], $month, $day];
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->fail($field, 'object_required');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                $this->fail($field, 'object_required');
            }
            $object[$key] = $item;
        }

        return $object;
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
