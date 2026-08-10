<?php

namespace App\Application\Event;

use App\Api\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class EventPlaceDataPolicy
{
    public function resolved(mixed $value, string $field = 'place'): EventPlaceData
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid($field, 'object_required');
        }
        $this->assertExactKeys($value, ['name', 'coordinates'], $field);
        if (!is_string($value['name'])) {
            $this->invalid($field . '.name', 'text_required');
        }
        $name = trim($value['name']);
        if ('' === $name || mb_strlen($name) > 500) {
            $this->invalid($field . '.name', 'text_length_invalid');
        }
        $coordinates = $value['coordinates'];
        if (!is_array($coordinates) || array_is_list($coordinates)) {
            $this->invalid($field . '.coordinates', 'object_required');
        }
        $this->assertExactKeys(
            $coordinates,
            ['latitude', 'longitude'],
            $field . '.coordinates',
        );
        $latitude = $this->coordinate(
            $coordinates['latitude'],
            -90,
            90,
            $field . '.coordinates.latitude',
        );
        $longitude = $this->coordinate(
            $coordinates['longitude'],
            -180,
            180,
            $field . '.coordinates.longitude',
        );

        return new EventPlaceData($name, $latitude, $longitude);
    }

    private function coordinate(
        mixed $value,
        float $minimum,
        float $maximum,
        string $field,
    ): float {
        if (!is_int($value) && !is_float($value)) {
            $this->invalid($field, 'number_required');
        }
        $coordinate = (float)$value;
        if (!is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            $this->invalid($field, 'coordinate_invalid');
        }

        return $coordinate;
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $expected
     */
    private function assertExactKeys(array $payload, array $expected, string $field): void
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            $this->invalid($field, 'fields_invalid');
        }
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'EVENT_PLACE_INVALID', 'event_place_invalid', [$field => [$error]]);
    }
}
