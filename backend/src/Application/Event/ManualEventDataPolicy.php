<?php

namespace App\Application\Event;

use App\Api\ApiException;
use App\Application\Person\PersonDataPolicy;
use App\Entity\Person;
use App\Entity\Value\EventType;
use App\Entity\Value\GenealogicalDate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class ManualEventDataPolicy
{
    public function __construct(
        private PersonDataPolicy $personData,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function event(Person $person, array $payload): ManualEventData
    {
        if (!is_string($payload['type'] ?? null)) {
            $this->invalid('type', 'manual_event_type_invalid');
        }
        $type = EventType::tryFrom($payload['type']);
        if (EventType::CUSTOM === $type) {
            $this->assertExactKeys(
                $payload,
                ['type', 'date', 'title', 'description'],
            );
            $title = $this->requiredText(
                $payload['title'],
                'title',
                200,
            );
            $description = $this->description($payload['description']);
            $data = [];
        } elseif (EventType::SURNAME_CHANGE === $type) {
            $this->assertExactKeys(
                $payload,
                ['type', 'date', 'newSurname'],
            );
            $title = null;
            $description = null;
            $data = [
                'newSurname' => $this->requiredText(
                    $payload['newSurname'],
                    'newSurname',
                    100,
                ),
            ];
        } else {
            $this->invalid('type', 'manual_event_type_invalid');
        }

        $date = $this->personData->dateValue($payload['date'], 'date');
        $this->assertWithinLife($person, $date);

        return new ManualEventData(
            $type,
            $date,
            $title,
            $description,
            $data,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function eventOrder(array $payload): array
    {
        $this->assertExactKeys($payload, ['eventIds']);
        if (!is_array($payload['eventIds']) || count($payload['eventIds']) < 2) {
            $this->invalid('eventIds', 'at_least_two_events_required');
        }

        $ids = [];
        foreach ($payload['eventIds'] as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                $this->invalid('eventIds', 'event_id_invalid');
            }
            $ids[] = $id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $this->invalid('eventIds', 'event_ids_must_be_unique');
        }

        return $ids;
    }

    private function assertWithinLife(
        Person $person,
        GenealogicalDate $date,
    ): void {
        $eventLatest = $date->latest();
        $birthEarliest = $person->getBirthDate()->earliest();
        if (
            null !== $eventLatest
            && null !== $birthEarliest
            && $eventLatest < $birthEarliest
        ) {
            $this->invalid('date', 'event_before_birth');
        }

        $deathDate = $person->getDeathDate();
        $eventEarliest = $date->earliest();
        $deathLatest = $deathDate?->latest();
        if (
            null !== $eventEarliest
            && null !== $deathLatest
            && $deathLatest < $eventEarliest
        ) {
            $this->invalid('date', 'event_after_death');
        }
    }

    private function requiredText(
        mixed $value,
        string $field,
        int $maxLength,
    ): string {
        if (!is_string($value)) {
            $this->invalid($field, 'text_required');
        }
        $normalized = trim($value);
        if ('' === $normalized || mb_strlen($normalized) > $maxLength) {
            $this->invalid($field, 'text_length_invalid');
        }

        return $normalized;
    }

    private function description(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 5000) {
            $this->invalid('description', 'description_length_invalid');
        }

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $expected
     */
    private function assertExactKeys(array $payload, array $expected): void
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            $this->invalid('event', 'event_fields_invalid');
        }
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'EVENT_DATA_INVALID',
            'event_data_invalid',
            [$field => [$error]],
        );
    }
}
