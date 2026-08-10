<?php

namespace App\Application\TreeExchange;

use App\Api\ApiException;
use App\Entity\Value\DateKind;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use Symfony\Component\HttpFoundation\JsonResponse;

final class Gedcom55Parser
{
    /** @var array<string, int> */
    private array $warnings = [];

    /** @var array<string, GedcomNode> */
    private array $records = [];

    public function parse(string $contents): GedcomImportDocument
    {
        $this->warnings = [];
        $this->records = [];
        $contents = str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
        if (!preg_match('/(?:^|\r\n|\n|\r)0 HEAD(?:\r\n|\n|\r|$)/', $contents)) {
            $this->fail('TREE_IMPORT_FORMAT_UNRECOGNIZED');
        }
        $roots = $this->nodes($contents);
        $head = $this->firstRoot($roots, 'HEAD');
        if (null === $head) {
            $this->fail('TREE_IMPORT_FORMAT_UNRECOGNIZED');
        }
        $version = $head->child('GEDC')?->child('VERS')->value ?? null;
        if (!in_array($version, ['5.5', '5.5.1'], true)) {
            if (is_string($version) && '' !== $version) {
                throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'TREE_IMPORT_FORMAT_UNSUPPORTED', 'tree_import_format_unsupported', details: ['format' => 'GEDCOM ' . $version]);
            }
            $this->fail('TREE_IMPORT_FORMAT_UNRECOGNIZED');
        }
        $charset = strtoupper($head->child('CHAR')->value ?? '');
        if (!in_array($charset, ['UTF-8', 'UTF8', 'ASCII'], true)) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'TREE_IMPORT_ENCODING_UNSUPPORTED', 'tree_import_encoding_unsupported', details: ['encoding' => '' === $charset ? null : $charset]);
        }
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $this->fail('TREE_IMPORT_ENCODING_INVALID');
        }
        if ('ASCII' === $charset && preg_match('/[^\x00-\x7F]/', $contents)) {
            $this->fail('TREE_IMPORT_ENCODING_INVALID');
        }
        if (null === $this->firstRoot($roots, 'TRLR')) {
            $this->fail('TREE_IMPORT_INVALID');
        }

        foreach ($roots as $root) {
            if (null !== $root->xref) {
                if (isset($this->records[$root->xref])) {
                    $this->fail('TREE_IMPORT_DUPLICATE_REFERENCE');
                }
                $this->records[$root->xref] = $root;
            }
        }

        $people = [];
        /** @var array<string, array<string, string>> $pedigrees */
        $pedigrees = [];
        foreach ($roots as $root) {
            if ('INDI' !== $root->tag) {
                continue;
            }
            if (null === $root->xref) {
                $this->fail('TREE_IMPORT_INVALID');
            }
            [$person, $personPedigrees] = $this->person($root);
            $people[] = $person;
            $pedigrees[$root->xref] = $personPedigrees;
        }
        if ([] === $people) {
            $this->fail('TREE_IMPORT_EMPTY');
        }

        $families = [];
        foreach ($roots as $root) {
            if ('FAM' === $root->tag) {
                $families[] = $this->family($root, $pedigrees);
            } elseif (!in_array($root->tag, ['HEAD', 'INDI', 'SUBM', 'TRLR'], true)) {
                $this->warning('unsupported_tag');
            }
        }
        [$people, $families] = $this->validateGraph($people, $families);

        return new GedcomImportDocument(
            'GEDCOM ' . $version,
            $people,
            $families,
            $this->warnings,
        );
    }

    /** @return list<GedcomNode> */
    private function nodes(string $contents): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents);
        if (false === $lines) {
            $this->fail('TREE_IMPORT_INVALID');
        }
        $roots = [];
        /** @var array<int, GedcomNode> $stack */
        $stack = [];
        foreach ($lines as $index => $line) {
            if ('' === $line && $index === array_key_last($lines)) {
                continue;
            }
            if (!preg_match(
                '/^(\d+) (?:(@[^@\s]+@) )?([A-Za-z0-9_]+)(?: (.*))?$/',
                $line,
                $matches,
            )) {
                $this->fail('TREE_IMPORT_INVALID');
            }
            $node = new GedcomNode(
                (int)$matches[1],
                '' === $matches[2] ? null : $matches[2],
                strtoupper($matches[3]),
                $matches[4] ?? '',
            );
            if (0 === $node->level) {
                $roots[] = $node;
                $stack = [0 => $node];
                continue;
            }
            $parent = $stack[$node->level - 1] ?? null;
            if (null === $parent) {
                $this->fail('TREE_IMPORT_INVALID');
            }
            $parent->children[] = $node;
            $stack[$node->level] = $node;
            foreach (array_keys($stack) as $level) {
                if ($level > $node->level) {
                    unset($stack[$level]);
                }
            }
        }

        return $roots;
    }

    /**
     * @return array{array{
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
     * }, array<string, string>}
     */
    private function person(GedcomNode $node): array
    {
        $names = $node->children('NAME');
        if (1 < count($names)) {
            $this->warning('additional_name', count($names) - 1);
        }
        $name = $names[0] ?? null;
        $givenNode = $name?->child('GIVN');
        $surnameNode = $name?->child('SURN');
        $givenName = null === $givenNode ? null : $this->continued($givenNode);
        $surname = null === $surnameNode ? null : $this->continued($surnameNode);
        if (null !== $name && (null === $givenName || null === $surname)) {
            [$fallbackGiven, $fallbackSurname] = $this->splitName($this->continued($name));
            $givenName ??= $fallbackGiven;
            $surname ??= $fallbackSurname;
        }
        $givenName = $this->text($givenName, 100);
        $surname = $this->text($surname, 100);
        $gender = match (strtoupper($node->child('SEX')->value ?? '')) {
            'M'     => Gender::MALE,
            'F'     => Gender::FEMALE,
            default => Gender::UNKNOWN,
        };
        $birth = $node->child('BIRT');
        $death = $node->child('DEAT');
        $events = [];
        foreach (['BAPM', 'BURI', 'OCCU', 'RESI', 'EVEN'] as $tag) {
            foreach ($node->children($tag) as $event) {
                $typeNode = $event->child('TYPE');
                $title = 'EVEN' === $tag
                    ? $this->text(
                        null === $typeNode ? null : $this->continued($typeNode),
                        200,
                    )
                    : null;
                $description = trim(implode("\n", array_filter([
                    $this->continued($event),
                    $event->child('NOTE') ? $this->continued($event->child('NOTE')) : null,
                ], static fn (?string $value): bool => null !== $value && '' !== $value)));
                $events[] = [
                    'date'        => $this->date($event->child('DATE')?->value),
                    'gedcomTag'   => $tag,
                    'title'       => $title,
                    'description' => null === ($text = $this->text($description, 5000))
                        ? null
                        : $text,
                    'place' => $this->place($event),
                ];
                $this->warnUnsupportedChildren($event, ['DATE', 'TYPE', 'NOTE', 'PLAC']);
            }
        }
        /** @var array<string, string> $personPedigrees */
        $personPedigrees = [];
        foreach ($node->children('FAMC') as $familyLink) {
            $personPedigrees[$familyLink->value] = strtoupper(
                $familyLink->child('PEDI')->value ?? 'BIRTH',
            );
        }
        $this->warnUnsupportedChildren($node, [
            'NAME', 'SEX', 'BIRT', 'DEAT', 'FAMC', 'FAMS', 'BAPM', 'BURI',
            'OCCU', 'RESI', 'EVEN', 'REFN',
        ]);
        if (null !== $name) {
            $this->warnUnsupportedChildren($name, ['GIVN', 'SURN']);
        }
        if (null !== $birth) {
            $this->warnUnsupportedChildren($birth, ['DATE', 'PLAC']);
        }
        if (null !== $death) {
            $this->warnUnsupportedChildren($death, ['DATE', 'PLAC']);
        }

        return [[
            'id'           => $node->xref ?? '',
            'birthSurname' => $surname,
            'givenName'    => $givenName,
            'gender'       => $gender,
            'birthDate'    => $this->date($birth?->child('DATE')?->value),
            'birthPlace'   => null === $birth ? null : $this->place($birth),
            'deathDate'    => null === $death
                ? null
                : $this->date($death->child('DATE')?->value),
            'deathPlace' => null === $death ? null : $this->place($death),
            'events'     => $events,
        ], $personPedigrees];
    }

    /**
     * @param array<string, array<string, string>> $pedigrees
     *
     * @return array{
     *     husband: string|null,
     *     wife: string|null,
     *     children: list<string>,
     *     marriageDate: GenealogicalDate,
     *     marriagePlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     dissolutionDate: GenealogicalDate|null,
     *     dissolutionPlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     unmarried: bool
     * }
     */
    private function family(GedcomNode $node, array $pedigrees): array
    {
        $husband = $node->child('HUSB')->value ?? null;
        $wife = $node->child('WIFE')->value ?? null;
        $children = [];
        foreach ($node->children('CHIL') as $child) {
            $pedigree = $pedigrees[$child->value][$node->xref ?? ''] ?? 'BIRTH';
            if (!in_array($pedigree, ['', 'BIRTH'], true)) {
                $this->warning('non_birth_parentage');
                continue;
            }
            $children[] = $child->value;
        }
        $marriage = $node->child('MARR');
        $divorce = $node->child('DIV');
        $this->warnUnsupportedChildren($node, [
            'HUSB', 'WIFE', 'CHIL', 'MARR', 'DIV', '_MGT_UNMARRIED',
        ]);
        if (null !== $marriage) {
            $this->warnUnsupportedChildren($marriage, ['DATE', 'PLAC']);
        }
        if (null !== $divorce) {
            $this->warnUnsupportedChildren($divorce, ['DATE', 'PLAC']);
        }

        return [
            'husband'         => $husband,
            'wife'            => $wife,
            'children'        => array_values(array_unique($children)),
            'marriageDate'    => $this->date($marriage?->child('DATE')?->value),
            'marriagePlace'   => null === $marriage ? null : $this->place($marriage),
            'dissolutionDate' => null === $divorce
                ? null
                : $this->date($divorce->child('DATE')?->value),
            'dissolutionPlace' => null === $divorce ? null : $this->place($divorce),
            'unmarried'        => null !== $node->child('_MGT_UNMARRIED'),
        ];
    }

    /** @return array{name: string, latitude: float|null, longitude: float|null}|null */
    private function place(GedcomNode $event): ?array
    {
        $placeNode = $event->child('PLAC');
        if (null === $placeNode) {
            return null;
        }
        $name = $this->text($this->continued($placeNode), 500);
        if (null === $name) {
            $this->warning('place_name_invalid');

            return null;
        }
        $map = $placeNode->child('MAP');
        $this->warnUnsupportedChildren($placeNode, ['MAP']);
        if (null === $map) {
            return ['name' => $name, 'latitude' => null, 'longitude' => null];
        }
        $this->warnUnsupportedChildren($map, ['LATI', 'LONG']);
        $latitude = $this->coordinate($map->child('LATI')?->value, true);
        $longitude = $this->coordinate($map->child('LONG')?->value, false);
        if (null === $latitude || null === $longitude) {
            $this->warning('place_coordinates_invalid');

            return ['name' => $name, 'latitude' => null, 'longitude' => null];
        }

        return ['name' => $name, 'latitude' => $latitude, 'longitude' => $longitude];
    }

    private function coordinate(?string $value, bool $latitude): ?float
    {
        if (null === $value) {
            return null;
        }
        $normalized = strtoupper(trim($value));
        if (!preg_match('/^([NSEW])\s*([0-9]+(?:\.[0-9]+)?)$/', $normalized, $matches)
            && !preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([NSEW])$/', $normalized, $suffix)
        ) {
            return null;
        }
        if (isset($suffix[1])) {
            $direction = $suffix[2];
            $magnitude = (float)$suffix[1];
        } else {
            $direction = $matches[1];
            $magnitude = (float)$matches[2];
        }
        if ($latitude ? !in_array($direction, ['N', 'S'], true) : !in_array($direction, ['E', 'W'], true)) {
            return null;
        }
        $maximum = $latitude ? 90 : 180;
        if ($magnitude > $maximum) {
            return null;
        }

        return in_array($direction, ['S', 'W'], true) ? -$magnitude : $magnitude;
    }

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
     *
     * @return array{list<array{
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
     * }>, list<array{
     *     husband: string|null,
     *     wife: string|null,
     *     children: list<string>,
     *     marriageDate: GenealogicalDate,
     *     marriagePlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     dissolutionDate: GenealogicalDate|null,
     *     dissolutionPlace: array{name: string, latitude: float|null, longitude: float|null}|null,
     *     unmarried: bool
     * }>}
     */
    private function validateGraph(array $people, array $families): array
    {
        /** @var array<string, int> $personIndexes */
        $personIndexes = [];
        foreach ($people as $index => $person) {
            $personIndexes[$person['id']] = $index;
            if ($this->before($person['deathDate'], $person['birthDate'])) {
                $this->fail('TREE_IMPORT_CHRONOLOGY_CONFLICT');
            }
            foreach ($person['events'] as $event) {
                $this->assertWithinLife(
                    $event['date'],
                    $person['birthDate'],
                    'BURI' === $event['gedcomTag'] ? null : $person['deathDate'],
                );
            }
        }
        /** @var array<string, array<string, true>> $adjacency */
        $adjacency = array_fill_keys(array_keys($personIndexes), []);
        /** @var array<string, array<string, true>> $children */
        $children = [];
        /** @var array<string, array<string, true>> $roles */
        $roles = [];
        foreach ($families as $familyIndex => $family) {
            foreach (['husband' => Gender::MALE, 'wife' => Gender::FEMALE] as $key => $requiredGender) {
                $id = $family[$key];
                if (null === $id) {
                    continue;
                }
                if (!isset($personIndexes[$id])) {
                    $this->fail('TREE_IMPORT_BROKEN_REFERENCE');
                }
                $index = $personIndexes[$id];
                $gender = $people[$index]['gender'];
                if (Gender::UNKNOWN === $gender) {
                    $people[$index] = [
                        ...$people[$index],
                        'gender' => $requiredGender,
                    ];
                } elseif ($requiredGender !== $gender) {
                    $this->fail('TREE_IMPORT_GENDER_CONFLICT');
                }
            }
            $husband = $family['husband'];
            $wife = $family['wife'];
            if ($this->before($family['dissolutionDate'], $family['marriageDate'])) {
                $this->fail('TREE_IMPORT_CHRONOLOGY_CONFLICT');
            }
            $dissolutionAfterDeath = false;
            foreach (array_filter([$husband, $wife]) as $spouse) {
                $person = $people[$personIndexes[$spouse]];
                $this->assertWithinLife($family['marriageDate'], $person['birthDate'], $person['deathDate']);
                if (null !== $family['dissolutionDate']) {
                    $this->assertWithinLife($family['dissolutionDate'], $person['birthDate'], null);
                    $dissolutionAfterDeath = $dissolutionAfterDeath
                        || $this->afterDeath($family['dissolutionDate'], $person['deathDate']);
                }
            }
            if ($dissolutionAfterDeath) {
                $family['dissolutionDate'] = GenealogicalDate::unknown();
                $families[$familyIndex] = $family;
                $this->warning('chronology_adjusted');
            }
            if (null !== $husband && null !== $wife && !$family['unmarried']) {
                $this->connect($adjacency, $husband, $wife);
            }
            foreach ($family['children'] as $child) {
                if (!isset($personIndexes[$child])) {
                    $this->fail('TREE_IMPORT_BROKEN_REFERENCE');
                }
                foreach (['husband' => 'father', 'wife' => 'mother'] as $key => $role) {
                    $parent = $family[$key];
                    if (null === $parent) {
                        continue;
                    }
                    if (isset($roles[$child][$role])) {
                        $this->fail('TREE_IMPORT_PARENT_CONFLICT');
                    }
                    $roles[$child][$role] = true;
                    $children[$parent][$child] = true;
                    $this->connect($adjacency, $parent, $child);
                    $parentPerson = $people[$personIndexes[$parent]];
                    $childPerson = $people[$personIndexes[$child]];
                    $this->assertWithinLife(
                        $childPerson['birthDate'],
                        $parentPerson['birthDate'],
                        $parentPerson['deathDate'],
                    );
                }
            }
        }
        foreach (array_keys($adjacency) as $person) {
            if ($this->reaches($children, $person, $person)) {
                $this->fail('TREE_IMPORT_ANCESTOR_CYCLE');
            }
        }
        $first = array_key_first($adjacency);
        $visited = [];
        $pending = null === $first ? [] : [$first];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach (array_keys($adjacency[$current]) as $next) {
                $pending[] = $next;
            }
        }
        if (count($visited) !== count($adjacency)) {
            $this->fail('TREE_IMPORT_DISCONNECTED');
        }

        return [$people, $families];
    }

    private function afterDeath(
        GenealogicalDate $date,
        ?GenealogicalDate $deathDate,
    ): bool {
        return null !== $date->earliest()
            && null !== $deathDate?->latest()
            && $date->earliest() > $deathDate->latest();
    }

    private function assertWithinLife(
        GenealogicalDate $date,
        GenealogicalDate $birthDate,
        ?GenealogicalDate $deathDate,
    ): void {
        $dateLatest = $date->latest();
        $birthEarliest = $birthDate->earliest();
        if (null !== $dateLatest && null !== $birthEarliest && $dateLatest < $birthEarliest) {
            $this->fail('TREE_IMPORT_CHRONOLOGY_CONFLICT');
        }
        $dateEarliest = $date->earliest();
        $deathLatest = $deathDate?->latest();
        if (null !== $dateEarliest && null !== $deathLatest && $dateEarliest > $deathLatest) {
            $this->fail('TREE_IMPORT_CHRONOLOGY_CONFLICT');
        }
    }

    private function before(?GenealogicalDate $left, GenealogicalDate $right): bool
    {
        return null !== $left?->latest()
            && null !== $right->earliest()
            && $left->latest() < $right->earliest();
    }

    /** @param array<string, array<string, true>> $children */
    private function reaches(array $children, string $start, string $target): bool
    {
        $pending = array_keys($children[$start] ?? []);
        $visited = [];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if ($target === $current) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach (array_keys($children[$current] ?? []) as $next) {
                $pending[] = $next;
            }
        }

        return false;
    }

    private function date(?string $value): GenealogicalDate
    {
        $value = trim($value ?? '');
        if ('' === $value) {
            return GenealogicalDate::unknown();
        }
        if (preg_match('/^(ABT|CAL|EST) (.+)$/i', $value, $matches)) {
            $parts = $this->plainDate($matches[2]);
            if (null !== $parts) {
                return GenealogicalDate::known(DateKind::APPROXIMATE, ...$parts);
            }
        } elseif (preg_match('/^BEF (.+)$/i', $value, $matches)) {
            $parts = $this->plainDate($matches[1]);
            if (null !== $parts) {
                return GenealogicalDate::known(DateKind::BEFORE, ...$parts);
            }
        } elseif (preg_match('/^AFT (.+)$/i', $value, $matches)) {
            $parts = $this->plainDate($matches[1]);
            if (null !== $parts) {
                return GenealogicalDate::known(DateKind::AFTER, ...$parts);
            }
        } elseif (preg_match('/^BET (.+) AND (.+)$/i', $value, $matches)) {
            $start = $this->plainDate($matches[1]);
            $end = $this->plainDate($matches[2]);
            if (null !== $start && null !== $end) {
                $startDate = GenealogicalDate::known(DateKind::EXACT, ...$start);
                $endDate = GenealogicalDate::known(DateKind::EXACT, ...$end);
                if ($startDate->earliest() <= $endDate->latest()) {
                    return GenealogicalDate::known(DateKind::BETWEEN, ...$start, ...$end);
                }
            }
        } else {
            $parts = $this->plainDate($value);
            if (null !== $parts) {
                return GenealogicalDate::known(DateKind::EXACT, ...$parts);
            }
        }

        $this->warning('unsupported_date');

        return GenealogicalDate::unknown();
    }

    /** @return array{int, int|null, int|null}|null */
    private function plainDate(string $value): ?array
    {
        $value = trim($value);
        $months = [
            'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4,
            'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8,
            'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        ];
        if (preg_match('/^(\d{4})$/', $value, $matches)) {
            $year = (int)$matches[1];
            if ($year >= 1582) {
                return [$year, null, null];
            }
        } elseif (preg_match('/^([A-Z]{3}) (\d{4})$/i', $value, $matches)) {
            $month = $months[strtoupper($matches[1])] ?? null;
            $year = (int)$matches[2];
            if (null !== $month && $year >= 1582) {
                return [$year, $month, null];
            }
        } elseif (preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/i', $value, $matches)) {
            $day = (int)$matches[1];
            $month = $months[strtoupper($matches[2])] ?? null;
            $year = (int)$matches[3];
            if (null !== $month && $year >= 1582 && checkdate($month, $day, $year)) {
                return [$year, $month, $day];
            }
        }

        return null;
    }

    /** @return array{string|null, string|null} */
    private function splitName(string $value): array
    {
        if (preg_match('/^\s*(.*?)\s*\/([^\/]*)\//u', $value, $matches)) {
            return [$this->nullable($matches[1]), $this->nullable($matches[2])];
        }

        return [$this->nullable($value), null];
    }

    private function continued(GedcomNode $node): string
    {
        $value = $node->value;
        foreach ($node->children as $child) {
            if ('CONC' === $child->tag) {
                $value .= $child->value;
            } elseif ('CONT' === $child->tag) {
                $value .= "\n" . $child->value;
            }
        }

        return $value;
    }

    private function text(?string $value, int $limit): ?string
    {
        $value = $this->nullable($value);
        if (null === $value) {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            $this->warning('value_truncated');
            $value = mb_substr($value, 0, $limit);
        }

        return $value;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' === $value ? null : $value;
    }

    /** @param list<string> $supported */
    private function warnUnsupportedChildren(GedcomNode $node, array $supported): void
    {
        foreach ($node->children as $child) {
            if (in_array($child->tag, $supported, true)) {
                continue;
            }
            $code = match ($child->tag) {
                'PLAC'  => 'unsupported_place',
                'SOUR'  => 'unsupported_source',
                'OBJE'  => 'unsupported_media',
                'NOTE'  => 'unsupported_note',
                default => 'unsupported_tag',
            };
            $this->warning($code);
        }
    }

    /** @param list<GedcomNode> $roots */
    private function firstRoot(array $roots, string $tag): ?GedcomNode
    {
        foreach ($roots as $root) {
            if ($tag === $root->tag) {
                return $root;
            }
        }

        return null;
    }

    /** @param array<string, array<string, true>> $adjacency */
    private function connect(array &$adjacency, string $first, string $second): void
    {
        if (!isset($adjacency[$first], $adjacency[$second])) {
            $this->fail('TREE_IMPORT_BROKEN_REFERENCE');
        }
        $adjacency[$first][$second] = true;
        $adjacency[$second][$first] = true;
    }

    private function warning(string $code, int $count = 1): void
    {
        $this->warnings[$code] = ($this->warnings[$code] ?? 0) + $count;
    }

    private function fail(string $code): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, $code, strtolower($code));
    }
}
