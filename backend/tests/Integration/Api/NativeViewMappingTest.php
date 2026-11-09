<?php

namespace App\Tests\Integration\Api;

use App\Api\View\DateEndView;
use App\Api\View\EventView;
use App\Api\View\GenealogicalDateView;
use App\Api\View\MarriagePeriodView;
use App\Api\View\PersonView;
use App\Api\View\RelationshipPersonView;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\DateKind;
use App\Entity\Value\EventType;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class NativeViewMappingTest extends KernelTestCase
{
    public function testNestedMappingsSelectTheDeclaredTargetAndPreserveUnknownValues(): void
    {
        $mapper = self::getContainer()->get(ObjectMapperInterface::class);
        $person = $this->person(null);
        $person->changeBirthPlace(EventPlace::resolved('Новосибирск', 55.03, 82.92), $person->getUpdatedAt());
        $view = $mapper->map($person, PersonView::class);

        self::assertSame('Ирина', $view->givenName->value);
        self::assertSame('unknown', $view->patronymic->state);
        self::assertSame('known', $view->birthDate->state);
        self::assertSame(1900, $view->birthDate->year);
        self::assertSame(1903, $view->birthDate->end?->year);
        self::assertSame('Новосибирск', $view->birthPlace?->name);
        self::assertSame(55.03, $view->birthPlace->coordinates?->latitude);
        self::assertNull($view->deathDate);

        // The same source supports multiple DTOs, including a date nested into itself as an interval end.
        $relationship = $mapper->map($person, RelationshipPersonView::class);
        self::assertSame('Ирина', $relationship->givenName->value);
        self::assertSame(1903, $relationship->birthDate->end?->year);
        self::assertNull($relationship->deathDate);
        $end = $mapper->map($person->getBirthDate(), DateEndView::class);
        self::assertSame(1903, $end->year);
    }

    public function testComputedDeathDateKeepsUnknownDistinctFromLivingInBothViews(): void
    {
        $mapper = self::getContainer()->get(ObjectMapperInterface::class);
        $person = $this->person(GenealogicalDate::unknown());
        foreach ([PersonView::class, RelationshipPersonView::class] as $target) {
            $view = $mapper->map($person, $target);
            self::assertInstanceOf(GenealogicalDateView::class, $view->deathDate);
            self::assertSame('unknown', $view->deathDate->state);
            self::assertSame('{"state":"unknown"}', $this->serialize($view->deathDate));
        }
        $unknownDate = $mapper->map(GenealogicalDate::unknown(), GenealogicalDateView::class);
        self::assertSame('{"state":"unknown"}', $this->serialize($unknownDate));
    }

    public function testMarriageAndEventSerializationPreserveTheirWireContracts(): void
    {
        $mapper = self::getContainer()->get(ObjectMapperInterface::class);
        $person = $this->person(null);
        $now = $person->getCreatedAt();
        $marriage = new MarriagePeriod(
            $person->getTree(),
            $this->person(null, $person->getTree(), Gender::MALE),
            $person,
            GenealogicalDate::unknown(),
            null,
            $now,
        );
        self::assertNull($mapper->map($marriage, MarriagePeriodView::class)->dissolutionDate);
        $marriage->changeDates(GenealogicalDate::unknown(), GenealogicalDate::unknown(), $now);
        self::assertSame('unknown', $mapper->map($marriage, MarriagePeriodView::class)->dissolutionDate?->state);

        $event = GenealogicalEvent::automaticPersonLifecycle($person, EventType::BIRTH, $person->getBirthDate(), $now);
        $view = $mapper->map($event, EventView::class);
        self::assertSame($person->getId(), $view->personId);
        self::assertSame(1903, $view->date->end?->year);
        self::assertInstanceOf(\stdClass::class, $view->data);
        self::assertStringContainsString('"data":{}', $this->serialize($view));
        self::assertStringContainsString('"source":"automatic"', $this->serialize($view));
    }

    private function person(
        ?GenealogicalDate $deathDate,
        ?FamilyTree $tree = null,
        Gender $gender = Gender::FEMALE,
    ): Person {
        $now = new \DateTimeImmutable('2026-11-09T00:00:00+00:00');
        $owner = new User('mapping', 'mapping@example.test', 'hash', 'ru', $now);
        $tree ??= new FamilyTree($owner, 'Mapping', $now);

        return new Person(
            $tree,
            KnownText::known('Петрова'),
            KnownText::known('Ирина'),
            KnownText::unknown(),
            $gender,
            GenealogicalDate::known(DateKind::BETWEEN, 1900, null, null, 1903),
            $deathDate,
            $now,
        );
    }

    private function serialize(object $view): string
    {
        return self::getContainer()->get(SerializerInterface::class)->serialize($view, 'json');
    }
}
