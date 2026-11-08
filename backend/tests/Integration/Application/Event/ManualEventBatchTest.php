<?php

namespace App\Tests\Integration\Application\Event;

use App\Api\ApiException;
use App\Application\Event\ManualEventService;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\DateKind;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class ManualEventBatchTest extends ApiWebTestCase
{
    private FamilyTree $tree;
    /** @var list<Person> */
    private array $people;
    private string $etag;

    protected function setUp(): void
    {
        parent::setUp();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $owner = new User('batch_user', 'batch@example.test', 'hash', 'en', $now);
        $this->tree = new FamilyTree($owner, 'Batch events', $now);
        $em->persist($owner);
        $em->persist($this->tree);
        $this->people = [];
        foreach ([1960, 1990, 1993] as $index => $year) {
            $person = new Person(
                $this->tree,
                KnownText::unknown(),
                KnownText::known('Participant ' . $index),
                KnownText::unknown(),
                Gender::FEMALE,
                GenealogicalDate::known(DateKind::EXACT, $year, null, null),
                null,
                $now,
            );
            $em->persist($person);
            $this->people[] = $person;
            if ($index > 0) {
                $em->persist(new ParentLink($this->tree, $this->people[0], $person, ParentRole::MOTHER));
            }
        }
        $this->tree->referencePerson($this->people[0]);
        $em->flush();
        $this->etag = static::getContainer()->get(TreeVersion::class)->etag($this->tree);
    }

    public function testCreatesIndependentEventsWithTextOnlyPlacesAndOneTreeRevision(): void
    {
        $service = static::getContainer()->get(ManualEventService::class);
        $counter = static::getContainer()->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        $events = $service->createMany($this->tree, $this->people, $this->payload(), $this->etag);
        self::assertLessThanOrEqual(4, $counter->selectCount());
        self::assertCount(3, $events);
        self::assertCount(3, array_unique(array_map(
            static fn (GenealogicalEvent $event): string => $event->getId()->toRfc4122(),
            $events,
        )));
        self::assertSame(2, $this->tree->getVersion());
        foreach ($events as $index => $event) {
            self::assertSame($this->people[$index], $event->getPerson());
            self::assertSame('Historical town', $event->getPlace()?->getName());
            self::assertNull($event->getPlace()->getLatitude());
        }
        $etag = static::getContainer()->get(TreeVersion::class)->etag($this->tree);
        $payload = $this->payload();
        $payload['description'] = 'Only one participant has this detail.';
        $service->update($this->tree, $this->people[0], $events[0]->getId()->toRfc4122(), $payload, $etag);
        self::assertSame('Only one participant has this detail.', $events[0]->getDescription());
        self::assertSame('A shared family occasion.', $events[1]->getDescription());
    }

    public function testInvalidParticipantRollsBackTheEntireBatch(): void
    {
        $service = static::getContainer()->get(ManualEventService::class);
        $treeId = $this->tree->getId();
        try {
            $service->createMany(
                $this->tree,
                $this->people,
                $this->payload(1991),
                $this->etag,
            );
            self::fail('An event before one participant was born must fail.');
        } catch (ApiException $error) {
            self::assertSame('EVENT_DATA_INVALID', $error->getErrorCode());
        }
        $em = static::getContainer()->get(ManagerRegistry::class)->resetManager();
        self::assertSame(0, $em->getRepository(GenealogicalEvent::class)->count());
        $tree = $em->find(FamilyTree::class, $treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        self::assertSame($this->etag, static::getContainer()->get(TreeVersion::class)->etag($tree));
    }

    public function testRejectsEmptyDuplicateAndForeignParticipants(): void
    {
        $service = static::getContainer()->get(ManualEventService::class);
        $foreign = new FamilyTree($this->tree->getOwner(), 'Another tree', new \DateTimeImmutable());
        foreach ([[], [$this->people[0], $this->people[0]]] as $people) {
            try {
                $service->createMany(
                    $this->tree,
                    $people,
                    $this->payload(),
                    $this->etag,
                );
                self::fail('Invalid participant lists must fail.');
            } catch (ApiException $error) {
                self::assertSame('EVENT_PARTICIPANTS_INVALID', $error->getErrorCode());
            }
        }
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('PERSON_NOT_FOUND');
        $service->createMany(
            $foreign,
            $this->people,
            $this->payload(),
            $this->etag,
        );
    }

    public function testStaleVersionDoesNotCreateAnotherBatch(): void
    {
        $service = static::getContainer()->get(ManualEventService::class);
        $service->createMany($this->tree, $this->people, $this->payload(), $this->etag);
        try {
            $service->createMany($this->tree, $this->people, $this->payload(), $this->etag);
            self::fail('A stale version must fail.');
        } catch (ApiException $error) {
            self::assertSame('TREE_VERSION_CONFLICT', $error->getErrorCode());
        }
        $em = static::getContainer()->get(ManagerRegistry::class)->resetManager();
        self::assertSame(3, $em->getRepository(GenealogicalEvent::class)->count());
    }

    /** @return array<string, mixed> */
    private function payload(int $year = 2010): array
    {
        return [
            'type'        => 'custom',
            'date'        => ['state' => 'known', 'kind' => 'exact', 'year' => $year],
            'title'       => 'Family celebration',
            'description' => 'A shared family occasion.',
            'place'       => ['name' => 'Historical town', 'coordinates' => null],
        ];
    }
}
