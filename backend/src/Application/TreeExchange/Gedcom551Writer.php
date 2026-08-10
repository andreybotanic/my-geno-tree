<?php

namespace App\Application\TreeExchange;

use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\Value\DatePrecision;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\ParentRole;

final class Gedcom551Writer
{
    /**
     * @param list<Person>            $people
     * @param list<ParentLink>        $parentLinks
     * @param list<MarriagePeriod>    $marriages
     * @param list<GenealogicalEvent> $events
     */
    public function write(
        string $fileName,
        array $people,
        array $parentLinks,
        array $marriages,
        array $events,
    ): string {
        /** @var array<string, string> $personXrefs */
        $personXrefs = [];
        foreach ($people as $index => $person) {
            $personXrefs[$this->id($person)] = '@I' . ($index + 1) . '@';
        }

        $families = $this->families($parentLinks, $marriages);
        /** @var array<string, list<string>> $personFamiliesAsSpouse */
        $personFamiliesAsSpouse = [];
        /** @var array<string, list<string>> $personFamiliesAsChild */
        $personFamiliesAsChild = [];
        foreach ($families as $index => &$family) {
            $family['xref'] = '@F' . ($index + 1) . '@';
            foreach (array_filter([$family['husband'], $family['wife']]) as $personId) {
                $personFamiliesAsSpouse[$personId][] = $family['xref'];
            }
            foreach ($family['children'] as $childId) {
                $personFamiliesAsChild[$childId][] = $family['xref'];
            }
        }
        unset($family);

        /** @var array<string, list<GenealogicalEvent>> $eventsByPerson */
        $eventsByPerson = [];
        /** @var array<string, array<string, GenealogicalEvent>> $lifecycleEvents */
        $lifecycleEvents = [];
        /** @var array<string, array<string, GenealogicalEvent>> $marriageEvents */
        $marriageEvents = [];
        foreach ($events as $event) {
            if (EventSource::MANUAL === $event->getSource()) {
                $eventsByPerson[$this->id($event->getPerson())][] = $event;
                continue;
            }
            if (in_array($event->getType(), [EventType::BIRTH, EventType::DEATH], true)) {
                $lifecycleEvents[$this->id($event->getPerson())][$event->getType()->value] = $event;
            }
            if (
                in_array($event->getType(), [EventType::MARRIAGE, EventType::DIVORCE], true)
                && null !== $event->getSourceEntityId()
            ) {
                $marriageEvents[$event->getSourceEntityId()->toRfc4122()][$event->getType()->value] = $event;
            }
        }

        $lines = [
            '0 HEAD',
            '1 SOUR MyGenoTree',
            '2 NAME MyGenoTree',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '1 CHAR UTF-8',
        ];
        $this->appendValue($lines, 1, 'FILE', $fileName . '.ged');
        foreach ($people as $person) {
            $personId = $this->id($person);
            $lines[] = '0 ' . $personXrefs[$personId] . ' INDI';
            $given = $person->getGivenName()->getValue() ?? '';
            $surname = $person->getBirthSurname()->getValue() ?? '';
            $this->appendValue($lines, 1, 'NAME', trim($given . ' /' . $surname . '/'));
            if ('' !== $given) {
                $this->appendValue($lines, 2, 'GIVN', $given);
            }
            if ('' !== $surname) {
                $this->appendValue($lines, 2, 'SURN', $surname);
            }
            $lines[] = '1 SEX ' . match ($person->getGender()) {
                Gender::MALE    => 'M',
                Gender::FEMALE  => 'F',
                Gender::UNKNOWN => 'U',
            };
            $lines[] = '1 BIRT';
            $this->appendDate($lines, 2, $person->getBirthDate());
            $this->appendPlace($lines, 2, ($lifecycleEvents[$personId][EventType::BIRTH->value] ?? null)?->getPlace());
            if (null !== $person->getDeathDate()) {
                $lines[] = '1 DEAT';
                $this->appendDate($lines, 2, $person->getDeathDate());
                $this->appendPlace($lines, 2, ($lifecycleEvents[$personId][EventType::DEATH->value] ?? null)?->getPlace());
            }
            $this->appendValue($lines, 1, 'REFN', $personId);
            $this->appendValue($lines, 2, 'TYPE', 'MyGenoTree UUID');
            foreach ($eventsByPerson[$personId] ?? [] as $event) {
                $gedcomTag = $this->manualEventTag($event);
                $lines[] = '1 ' . $gedcomTag;
                $this->appendDate($lines, 2, $event->getDate());
                $this->appendPlace($lines, 2, $event->getPlace());
                if ('EVEN' === $gedcomTag) {
                    $this->appendValue($lines, 2, 'TYPE', $event->getTitle() ?? 'EVEN');
                }
                if (null !== $event->getDescription()) {
                    $this->appendValue($lines, 2, 'NOTE', $event->getDescription());
                }
            }
            foreach ($personFamiliesAsSpouse[$personId] ?? [] as $xref) {
                $lines[] = '1 FAMS ' . $xref;
            }
            foreach ($personFamiliesAsChild[$personId] ?? [] as $xref) {
                $lines[] = '1 FAMC ' . $xref;
            }
        }

        foreach ($families as $family) {
            $lines[] = '0 ' . $family['xref'] . ' FAM';
            if (null !== $family['husband']) {
                $lines[] = '1 HUSB ' . $personXrefs[$family['husband']];
            }
            if (null !== $family['wife']) {
                $lines[] = '1 WIFE ' . $personXrefs[$family['wife']];
            }
            foreach ($family['children'] as $childId) {
                $lines[] = '1 CHIL ' . $personXrefs[$childId];
            }
            if ($family['marriage'] instanceof MarriagePeriod) {
                $lines[] = '1 MARR';
                $this->appendDate($lines, 2, $family['marriage']->getMarriageDate());
                $marriageId = $family['marriage']->getId()->toRfc4122();
                $this->appendPlace($lines, 2, ($marriageEvents[$marriageId][EventType::MARRIAGE->value] ?? null)?->getPlace());
                if (null !== $family['marriage']->getDissolutionDate()) {
                    $lines[] = '1 DIV';
                    $this->appendDate($lines, 2, $family['marriage']->getDissolutionDate());
                    $this->appendPlace($lines, 2, ($marriageEvents[$marriageId][EventType::DIVORCE->value] ?? null)?->getPlace());
                }
            } else {
                $lines[] = '1 _MGT_UNMARRIED Y';
            }
        }
        $lines[] = '0 TRLR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<ParentLink>     $parentLinks
     * @param list<MarriagePeriod> $marriages
     *
     * @return list<array{xref?: string, husband: string|null, wife: string|null, children: list<string>, marriage: MarriagePeriod|null}>
     */
    private function families(array $parentLinks, array $marriages): array
    {
        $families = [];
        /** @var array<string, int> $firstMarriageByPair */
        $firstMarriageByPair = [];
        foreach ($marriages as $marriage) {
            $husband = $this->id($marriage->getMalePerson());
            $wife = $this->id($marriage->getFemalePerson());
            $families[] = [
                'husband'  => $husband,
                'wife'     => $wife,
                'children' => [],
                'marriage' => $marriage,
            ];
            $firstMarriageByPair[$husband . ':' . $wife] ??= array_key_last($families);
        }

        /** @var array<string, array{husband: string|null, wife: string|null}> $parentsByChild */
        $parentsByChild = [];
        foreach ($parentLinks as $link) {
            $childId = $this->id($link->getChild());
            $parentsByChild[$childId] ??= ['husband' => null, 'wife' => null];
            $parentsByChild[$childId][ParentRole::FATHER === $link->getRole() ? 'husband' : 'wife'] = $this->id($link->getParent());
        }
        /** @var array<string, int> $unmarriedByPair */
        $unmarriedByPair = [];
        foreach ($parentsByChild as $childId => $parents) {
            $key = ($parents['husband'] ?? '-') . ':' . ($parents['wife'] ?? '-');
            $index = $firstMarriageByPair[$key] ?? $unmarriedByPair[$key] ?? null;
            if (null === $index) {
                $families[] = [
                    'husband'  => $parents['husband'],
                    'wife'     => $parents['wife'],
                    'children' => [],
                    'marriage' => null,
                ];
                $index = array_key_last($families);
                $unmarriedByPair[$key] = $index;
            }
            $families[$index]['children'][] = $childId;
        }

        return array_map(
            static fn (array $family): array => [
                'husband'  => $family['husband'] ?? null,
                'wife'     => $family['wife'] ?? null,
                'children' => $family['children'],
                'marriage' => $family['marriage'] ?? null,
            ],
            $families,
        );
    }

    /** @param list<string> $lines */
    private function appendDate(array &$lines, int $level, GenealogicalDate $date): void
    {
        $value = $this->date($date);
        if (null !== $value) {
            $lines[] = $level . ' DATE ' . $value;
        }
    }

    private function date(GenealogicalDate $date): ?string
    {
        if (!$date->isKnown()) {
            return null;
        }
        $months = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        $value = match ($date->getPrecision()) {
            DatePrecision::YEAR  => (string)$date->getYear(),
            DatePrecision::MONTH => $months[$date->getMonth() ?? 1] . ' ' . $date->getYear(),
            DatePrecision::DAY   => $date->getDay() . ' ' . $months[$date->getMonth() ?? 1] . ' ' . $date->getYear(),
            null                 => null,
        };

        return $date->isApproximate() && null !== $value ? 'ABT ' . $value : $value;
    }

    private function manualEventTag(GenealogicalEvent $event): string
    {
        if (EventType::RESIDENCE === $event->getType()) {
            return 'RESI';
        }

        return 'EVEN';
    }

    /** @param list<string> $lines */
    private function appendPlace(array &$lines, int $level, ?\App\Entity\EventPlace $place): void
    {
        if (null === $place) {
            return;
        }
        $this->appendValue($lines, $level, 'PLAC', $place->getName());
        $latitude = $place->getLatitude();
        $longitude = $place->getLongitude();
        if (null === $latitude || null === $longitude) {
            return;
        }
        $lines[] = ($level + 1) . ' MAP';
        $lines[] = ($level + 2) . ' LATI ' . $this->coordinate($latitude, 'N', 'S');
        $lines[] = ($level + 2) . ' LONG ' . $this->coordinate($longitude, 'E', 'W');
    }

    private function coordinate(float $coordinate, string $positive, string $negative): string
    {
        $direction = $coordinate < 0 ? $negative : $positive;
        $magnitude = rtrim(rtrim(number_format(abs($coordinate), 7, '.', ''), '0'), '.');

        return $direction . $magnitude;
    }

    /** @param list<string> $lines */
    private function appendValue(array &$lines, int $level, string $tag, string $value): void
    {
        $logicalLines = explode("\n", str_replace("\r\n", "\n", $value));
        foreach ($logicalLines as $lineIndex => $logicalLine) {
            $prefix = 0 === $lineIndex ? $level . ' ' . $tag : ($level + 1) . ' CONT';
            $chunks = $this->chunks($logicalLine, 200);
            foreach ($chunks as $chunkIndex => $chunk) {
                $lines[] = (0 === $chunkIndex ? $prefix : ($level + 1) . ' CONC')
                    . ('' === $chunk ? '' : ' ' . $chunk);
            }
        }
    }

    /** @return non-empty-list<string> */
    private function chunks(string $value, int $bytes): array
    {
        if ('' === $value) {
            return [''];
        }
        $chunks = [];
        while ('' !== $value) {
            $chunk = mb_strcut($value, 0, $bytes, 'UTF-8');
            $chunks[] = $chunk;
            $value = substr($value, strlen($chunk));
        }

        return $chunks;
    }

    private function id(Person $person): string
    {
        return $person->getId()->toRfc4122();
    }
}
