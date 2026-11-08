<?php

namespace App\Infrastructure\Doctrine\Hydration;

use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Hydrates genealogy read records without creating managed entities or loading their media. */
#[Exclude]
final class GenealogyRecordHydrator extends ArrayHydrator
{
    public const string MODE = 'genealogy_record';

    /** @return list<array<string, mixed>> */
    protected function hydrateAllData(): array
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = parent::hydrateAllData();

        return array_map($this->record(...), $rows);
    }

    /** @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function record(array $row): array
    {
        if (isset($row[0]) && is_array($row[0])) {
            $item = $row[0];
            unset($row[0]);
            $row = [...$item, ...$row];
        }
        $data = $embedded = [];
        foreach ($row as $key => $value) {
            assert(is_string($key));
            $value = match (true) {
                $value instanceof \BackedEnum                 => $value->value,
                $value instanceof \Symfony\Component\Uid\Uuid => $value->toRfc4122(),
                $value instanceof \DateTimeInterface          => $value->format(\DateTimeInterface::ATOM),
                default                                       => $value,
            };
            if (str_contains($key, '.')) {
                [$field, $part] = explode('.', $key, 2);
                $embedded[$field] = true;
                $data[$field] ??= [];
                assert(is_array($data[$field]));
                $data[$field][$part] = $value;
            } else {
                $data[$key] = $value;
            }
        }
        foreach ($data as $field => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (isset($embedded[$field])) {
                if ('unknown' === $value['state']) {
                    $data[$field] = ['state' => 'unknown'];
                    continue;
                }
                $value = array_filter($value, static fn (mixed $part): bool => null !== $part);
                if ('between' === ($value['kind'] ?? null)) {
                    foreach (['Year', 'Month', 'Day'] as $part) {
                        $endPart = 'end' . $part;
                        if (isset($value[$endPart])) {
                            $value['end'] ??= [];
                            assert(is_array($value['end']));
                            $value['end'][strtolower($part)] = $value[$endPart];
                        }
                    }
                }
                unset($value['endYear'], $value['endMonth'], $value['endDay']);
                $data[$field] = $value;
            } elseif (str_ends_with($field, 'Place') || 'place' === $field) {
                $latitude = $value['latitude'];
                $longitude = $value['longitude'];
                assert(null === $latitude || is_numeric($latitude));
                assert(null === $longitude || is_numeric($longitude));
                $data[$field] = [
                    'name'        => $value['name'],
                    'coordinates' => null === $latitude || null === $longitude ? null : [
                        'latitude'  => (float)$latitude,
                        'longitude' => (float)$longitude,
                    ],
                ];
            }
        }
        if (array_key_exists('deceased', $data)) {
            $data['deathDate'] = $data['deceased'] ? $data['storedDeathDate'] : null;
            unset($data['deceased'], $data['storedDeathDate']);
        }
        if (array_key_exists('dissolved', $data)) {
            $data['dissolutionDate'] = $data['dissolved'] ? $data['storedDissolutionDate'] : null;
            unset($data['dissolved'], $data['storedDissolutionDate']);
        }
        if (array_key_exists('data', $data) && [] === $data['data']) {
            $data['data'] = new \stdClass();
        }

        return $data;
    }
}
