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
     *     deathDate: GenealogicalDate|null,
     *     events: list<array{
     *         date: GenealogicalDate,
     *         gedcomTag: string,
     *         title: string|null,
     *         description: string|null
     *     }>
     * }> $people
     * @param list<array{
     *     husband: string|null,
     *     wife: string|null,
     *     children: list<string>,
     *     marriageDate: GenealogicalDate,
     *     dissolutionDate: GenealogicalDate|null,
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
            'format'      => $this->format,
            'personCount' => count($this->people),
            'familyCount' => count($this->families),
            'warnings'    => $warnings,
        ];
    }
}
