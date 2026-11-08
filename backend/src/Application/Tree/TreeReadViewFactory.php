<?php

namespace App\Application\Tree;

/** Converts ORM array projections without hydrating entities or their media associations. */
final class TreeReadViewFactory
{
    /** @param array<string, mixed> $row
     * @param list<'identity'|'life'|'places'|'metadata'> $groups
     *
     * @return array<string, mixed>
     */
    public function person(array $row, array $groups): array
    {
        $data = $this->normalize($row);
        $fields = ['id'];
        foreach ($groups as $group) {
            $fields = [...$fields, ...match ($group) {
                'identity' => ['givenName', 'birthSurname', 'patronymic', 'gender'],
                'life'     => ['birthDate', 'deathDate'],
                'places'   => ['birthPlace', 'deathPlace'],
                'metadata' => ['createdAt', 'updatedAt'],
            }];
        }
        $data['deathDate'] = ($data['deceased'] ?? false) ? $data['storedDeathDate'] : null;

        return array_intersect_key($data, array_fill_keys($fields, true));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function relationship(array $row, string $kind): array
    {
        $data = $this->normalize($row);
        if ('marriages' === $kind) {
            $data['dissolutionDate'] = $data['dissolved'] ? $data['storedDissolutionDate'] : null;
            unset($data['dissolved'], $data['storedDissolutionDate']);
        }

        return $data;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function event(array $row): array
    {
        $data = $this->normalize($row);
        if ([] === $data['data']) {
            $data['data'] = new \stdClass();
        }

        return $data;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $data = [];
        foreach ($row as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \Symfony\Component\Uid\Uuid) {
                $value = $value->toRfc4122();
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM);
            }
            if (str_contains($key, '.')) {
                [$field, $part] = explode('.', $key, 2);
                if (!isset($data[$field])) {
                    $data[$field] = [];
                }
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
            if (array_key_exists('state', $value)) {
                if ('unknown' === $value['state']) {
                    $data[$field] = ['state' => 'unknown'];
                } else {
                    $value = array_filter($value, static fn (mixed $part): bool => null !== $part);
                    if ('between' === ($value['kind'] ?? null)) {
                        foreach (['Year', 'Month', 'Day'] as $part) {
                            $endPart = 'end' . $part;
                            if (isset($value[$endPart])) {
                                $value['end'][strtolower($part)] = $value[$endPart];
                            }
                        }
                    }
                    unset($value['endYear'], $value['endMonth'], $value['endDay']);
                    $data[$field] = $value;
                }
            } elseif (str_ends_with($field, 'Place') || 'place' === $field) {
                $data[$field] = [
                    'name'        => $value['name'],
                    'coordinates' => null === $value['latitude'] || null === $value['longitude'] ? null : [
                        'latitude'  => (float)$value['latitude'],
                        'longitude' => (float)$value['longitude'],
                    ],
                ];
            }
        }

        return $data;
    }
}
