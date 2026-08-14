<?php

namespace App\Tests\Unit\Application\Tree\Layout;

use App\Application\Tree\Layout\TreeStructure;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use PHPUnit\Framework\TestCase;

final class TreeStructureTest extends TestCase
{
    public function testRevisionIsDeterministicAndIgnoresPresentationDataAndRepeatedPeriods(): void
    {
        $tree = $this->tree();
        $husband = $this->person($tree, Gender::MALE, 1);
        $wife = $this->person($tree, Gender::FEMALE, 2);
        $father = $this->person($tree, Gender::MALE, 3);
        $parentLink = new ParentLink($tree, $father, $husband, ParentRole::FATHER);
        $marriage = $this->marriage($tree, $husband, $wife, 4);

        $first = new TreeStructure(
            [$husband, $wife, $father],
            [$parentLink],
            [$marriage],
        );
        $revision = $first->revision($husband, null);

        $husband->update(
            KnownText::known('Changed'),
            KnownText::known('Name'),
            KnownText::unknown(),
            Gender::MALE,
            GenealogicalDate::unknown(),
            null,
            new \DateTimeImmutable('2026-08-14T12:00:00+00:00'),
        );
        $repeatedMarriage = $this->marriage($tree, $husband, $wife, 5);
        $reordered = new TreeStructure(
            [$father, $wife, $husband],
            [$parentLink],
            [$repeatedMarriage, $marriage],
        );

        self::assertSame($revision, $reordered->revision($husband, null));
        self::assertSame(
            $first->revision($husband, $wife),
            $reordered->revision($wife, $husband),
        );
    }

    public function testDistantHusbandAncestorChangesHusbandButNotWifeProjection(): void
    {
        $tree = $this->tree();
        $husband = $this->person($tree, Gender::MALE, 1);
        $wife = $this->person($tree, Gender::FEMALE, 2);
        $father = $this->person($tree, Gender::MALE, 3);
        $grandfather = $this->person($tree, Gender::MALE, 4);
        $marriage = $this->marriage($tree, $husband, $wife, 5);
        $fatherLink = new ParentLink($tree, $father, $husband, ParentRole::FATHER);
        $before = new TreeStructure(
            [$husband, $wife, $father],
            [$fatherLink],
            [$marriage],
        );
        $husbandRevision = $before->revision($husband, null);
        $wifeRevision = $before->revision($wife, null);

        $after = new TreeStructure(
            [$husband, $wife, $father, $grandfather],
            [
                $fatherLink,
                new ParentLink($tree, $grandfather, $father, ParentRole::FATHER),
            ],
            [$marriage],
        );

        self::assertNotSame($husbandRevision, $after->revision($husband, null));
        self::assertSame($wifeRevision, $after->revision($wife, null));
        self::assertContains($grandfather->getId()->toRfc4122(), $after->visiblePersonIds($husband, null));
        self::assertNotContains($grandfather->getId()->toRfc4122(), $after->visiblePersonIds($wife, null));
    }

    private function tree(): FamilyTree
    {
        $createdAt = new \DateTimeImmutable('2026-08-14T00:00:00+00:00');

        return new FamilyTree(
            new User('owner', 'owner@example.test', 'hash', 'en', $createdAt),
            'Tree',
            $createdAt,
        );
    }

    private function person(FamilyTree $tree, Gender $gender, int $second): Person
    {
        return new Person(
            $tree,
            KnownText::unknown(),
            KnownText::unknown(),
            KnownText::unknown(),
            $gender,
            GenealogicalDate::unknown(),
            null,
            new \DateTimeImmutable(sprintf('2026-08-14T00:00:%02d+00:00', $second)),
        );
    }

    private function marriage(
        FamilyTree $tree,
        Person $male,
        Person $female,
        int $second,
    ): MarriagePeriod {
        return new MarriagePeriod(
            $tree,
            $male,
            $female,
            GenealogicalDate::unknown(),
            null,
            new \DateTimeImmutable(sprintf('2026-08-14T00:00:%02d+00:00', $second)),
        );
    }
}
