<?php

namespace App\Application\TreeExchange;

use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;

final readonly class GedcomImportDocument
{
    /**
     * @param list<array{
     *     id: string,
     *     birthSurname: string|null,
     *     givenName: string|null,
     *     gender: Gender,
     *     birthDate: GenealogicalDate,
     *     birthPlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     deathDate: GenealogicalDate|null,
     *     deathPlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     events: list<array{
     *         date: GenealogicalDate,
     *         gedcomTag: string,
     *         title: string|null,
     *         description: string|null,
     *         place: array{name: string, latitude: float|null, longitude: float|null}|null
     *     }>
     * }> $people
     * @param list<array{
     *     husband: string|null,
     *     wife: string|null,
     *     children: list<string>,
     *     marriageDate: GenealogicalDate,
     *     marriagePlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     dissolutionDate: GenealogicalDate|null,
     *     dissolutionPlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     unmarried: bool
     * }> $families
     * @param array<string, int> $warnings
     */
    public function __construct(
        public string $format,
        public array $people,
        public array $families,
        public array $warnings,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspection(): array
    {
        $warnings = [];
        $warningCounts = $this->warnings;
        ksort($warningCounts);
        foreach ($warningCounts as $code => $count) {
            $warnings[] = ['code' => $code, 'count' => $count];
        }

        return [
            'format'              => $this->format,
            'personCount'         => count($this->people),
            'familyCount'         => count($this->families),
            'hasUnresolvedPlaces' => [] !== $this->unresolvedPlaceNames(),
            'warnings'            => $warnings,
        ];
    }

    /** @return list<string> */
    public function unresolvedPlaceNames(): array
    {
        /** @var array<string, true> $names */
        $names = [];
        foreach ($this->people as $person) {
            $this->addUnresolvedPlace($names, $person['birthPlace']);
            $this->addUnresolvedPlace($names, $person['deathPlace']);
            foreach ($person['events'] as $event) {
                $this->addUnresolvedPlace($names, $event['place']);
            }
        }
        foreach ($this->families as $family) {
            $this->addUnresolvedPlace($names, $family['marriagePlace']);
            $this->addUnresolvedPlace($names, $family['dissolutionPlace']);
        }

        return array_keys($names);
    }

    /**
     * @param array<string, true>                                                   $names
     * @param array{name: string, latitude: float|null, longitude: float|null}|null $place
     */
    private function addUnresolvedPlace(array &$names, ?array $place): void
    {
        if (null === $place || (null !== $place['latitude'] && null !== $place['longitude'])) {
            return;
        }
        $names[$place['name']] = true;
    }
}
