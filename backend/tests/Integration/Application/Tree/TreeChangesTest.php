<?php

namespace App\Tests\Integration\Application\Tree;

use App\Api\ApiException;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Application\Tree\ChangeSet\TreeChangeSetFactory;
use App\Application\Tree\ChangeSet\TreeChangeSetProjector;
use App\Application\Tree\TreeVersion;
use App\Application\TreeMerge\TreeMergeService;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\EventSource;
use App\Entity\Value\EventType;
use App\Repository\GenealogicalEventRepository;
use App\Tests\Api\ApiWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class TreeChangesTest extends ApiWebTestCase
{
    private FamilyTree $tree;
    /** @var array<string, string> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new \DateTimeImmutable();
        $owner = new User('tree_changes', 'changes@example.test', 'hash', 'en', $now);
        $this->tree = new FamilyTree($owner, 'Changes', $now);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($owner);
        $em->persist($this->tree);
        $em->flush();
        $result = $this->apply(
            [
                [
                    'type'   => 'create_person',
                    'id'     => 'mom',
                    'fields' => [
                        'givenName' => ['state' => 'known', 'value' => 'Mother'],
                        'gender'    => 'female',
                        'birthDate' => $this->date(1960),
                    ],
                ],
                [
                    'type'   => 'create_person',
                    'id'     => 'dad',
                    'fields' => [
                        'gender'    => 'male',
                        'birthDate' => $this->date(1958),
                    ],
                ],
                ['type' => 'create_person', 'id' => 'child', 'fields' => ['birthDate' => $this->date(1990)]],
                ['type' => 'add_parent_link', 'parent' => 'mom', 'child' => 'child', 'role' => 'mother'],
                ['type' => 'add_parent_link', 'parent' => 'dad', 'child' => 'child', 'role' => 'father'],
                [
                    'type'      => 'create_marriage_period',
                    'first'     => 'mom',
                    'second'    => 'dad',
                    'newPeriod' => false,
                    'fields'    => [
                        'marriageDate'     => $this->date(1980),
                        'femaleNewSurname' => 'Married',
                        'marriagePlace'    => ['name' => 'Village', 'coordinates' => null],
                    ],
                ],
            ],
        );
        $this->ids = $result['personIds'];
    }

    public function testCreatesCopiesAndPatchesDeletesAndPlacesIndependently(): void
    {
        $this->apply(
            [
                [
                    'type'      => 'create_events',
                    'personIds' => array_values($this->ids),
                    'event'     => [
                        'type'        => 'residence',
                        'date'        => $this->date(2000),
                        'description' => 'Moved together',
                        'place'       => ['name' => 'Historical settlement', 'coordinates' => null],
                    ],
                ],
            ],
        );
        $events = $this->manualEvents();
        self::assertCount(3, $events);
        $first = $events[0]->getId()->toRfc4122();
        $second = $events[1]->getId()->toRfc4122();
        $this->apply(
            [
                ['type' => 'update_event', 'eventId' => $first, 'fields' => ['description' => 'Only this record']],
                [
                    'type'    => 'set_event_place',
                    'eventId' => $first,
                    'place'   => [
                        'name'        => 'Chosen point',
                        'coordinates' => ['latitude' => 55, 'longitude' => 83],
                    ],
                ],
                ['type' => 'delete_event', 'eventId' => $second],
            ],
        );
        self::assertCount(2, $this->manualEvents());
        self::assertSame('Only this record', $events[0]->getDescription());
        self::assertSame(55.0, $events[0]->getPlace()?->getLatitude());
        self::assertSame('Moved together', $events[2]->getDescription());
        $this->apply([['type' => 'set_event_place', 'eventId' => $first, 'place' => null]]);
        self::assertNull($events[0]->getPlace());
    }

    public function testPersonPlacesAndMarriageDatesSynchronizeAutomaticEvents(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $marriage = $em->getRepository(MarriagePeriod::class)->findOneBy(['tree' => $this->tree]);
        self::assertInstanceOf(MarriagePeriod::class, $marriage);
        $this->apply(
            [
                [
                    'type'     => 'update_person',
                    'personId' => $this->ids['child'],
                    'fields'   => [
                        'birthPlace' => ['name' => 'Birth town', 'coordinates' => null],
                        'deathDate'  => $this->date(2020),
                        'deathPlace' => ['name' => 'Death town', 'coordinates' => null],
                    ],
                ],
                [
                    'type'       => 'update_marriage_period',
                    'marriageId' => $marriage->getId()->toRfc4122(),
                    'fields'     => [
                        'marriageDate'     => $this->date(1981),
                        'dissolutionDate'  => $this->date(2010),
                        'dissolutionPlace' => ['name' => 'Divorce town', 'coordinates' => null],
                    ],
                ],
            ],
        );
        $repo = static::getContainer()->get(GenealogicalEventRepository::class);
        $child = $em->find(Person::class, $this->ids['child']);
        self::assertInstanceOf(Person::class, $child);
        self::assertSame(
            'Birth town',
            $repo->findAutomaticPersonLifecycle($child, EventType::BIRTH)?->getPlace()?->getName(),
        );
        foreach ($repo->findAllForTree($this->tree) as $event) {
            if (EventType::CHILD_BIRTH === $event->getType()) {
                self::assertSame('Birth town', $event->getPlace()?->getName());
            }
            if (EventType::MARRIAGE === $event->getType()) {
                self::assertSame(1981, $event->getDate()->getYear());
            }
        }
        $this->apply(
            [
                [
                    'type'     => 'update_person',
                    'personId' => $this->ids['child'],
                    'fields'   => ['birthPlace' => null, 'deathDate' => null],
                ],
                [
                    'type'       => 'update_marriage_period',
                    'marriageId' => $marriage->getId()->toRfc4122(),
                    'fields'     => [
                        'dissolutionDate' => null,
                        'marriagePlace'   => null,
                    ],
                ],
            ],
        );
        self::assertNull($child->getBirthPlace());
        self::assertNull($child->getDeathPlace());
        self::assertNull($marriage->getDissolutionPlace());
        self::assertNull($marriage->getMarriagePlace());
        self::assertNotNull(
            $repo->findAutomaticForSource($marriage->getFemalePerson(), EventType::SURNAME_CHANGE, $marriage->getId()),
        );
    }

    public function testRemovalsUseTheFinalGraphAndRepairTheReference(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $mom = $em->find(Person::class, $this->ids['mom']);
        self::assertInstanceOf(Person::class, $mom);
        $this->tree->referencePerson($mom);
        $em->flush();
        $links = $em->getRepository(ParentLink::class)->findBy(['tree' => $this->tree]);
        $marriage = $em->getRepository(MarriagePeriod::class)->findOneBy(['tree' => $this->tree]);
        self::assertInstanceOf(MarriagePeriod::class, $marriage);
        $this->apply(
            [
                ['type' => 'remove_marriage_period', 'marriageId' => $marriage->getId()->toRfc4122()],
                ['type' => 'remove_parent_link', 'parentLinkId' => $links[0]->getId()->toRfc4122()],
                ['type' => 'delete_person', 'personId' => $links[0]->getParent()->getId()->toRfc4122()],
            ],
        );
        self::assertSame(2, $em->getRepository(Person::class)->count(['tree' => $this->tree]));
        self::assertNotNull($this->tree->getReferencePrimaryPerson());
        self::assertSame(0, $em->getRepository(MarriagePeriod::class)->count(['tree' => $this->tree]));
        self::assertSame(1, $em->getRepository(ParentLink::class)->count(['tree' => $this->tree]));
    }

    public function testPacketPreparationIncludesEveryOperationAndRevalidatesSavedEvidence(): void
    {
        $service = static::getContainer()->get(TreeMergeService::class);
        $operations = [
            [
                'type'     => 'create_person',
                'id'       => 'new',
                'fields'   => [],
                'evidence' => [
                    ['messageId' => 'm', 'quote' => 'Add a child and record the move.'],
                ],
            ],
            [
                'type'     => 'add_parent_link',
                'parent'   => $this->ids['mom'],
                'child'    => 'new',
                'role'     => 'mother',
                'evidence' => [
                    ['messageId' => 'm', 'quote' => 'Add a child and record the move.'],
                ],
            ],
            [
                'type'      => 'create_events',
                'personIds' => [$this->ids['mom'], 'new'],
                'event'     => [
                    'type'        => 'residence',
                    'date'        => $this->date(2000),
                    'description' => 'A move',
                ],
                'evidence' => [['messageId' => 'm', 'quote' => 'Add a child and record the move.']],
            ],
        ];
        $proposal = $service->prepare(
            $this->tree,
            ['operations' => $operations],
            [['id' => 'm', 'role' => 'user', 'content' => 'Add a child and record the move.']],
        );
        self::assertSame('ready', $proposal->state);
        self::assertIsArray($proposal->proposal['operations']);
        self::assertCount(3, $proposal->proposal['operations']);
        self::assertCount(0, $this->manualEvents());
        self::assertSame('ready', $service->revalidate($this->tree, $proposal)->state);
        $revised = $service->prepare($this->tree, $proposal->proposal, current: $proposal, manual: true);
        self::assertSame('ready', $revised->state);
        self::assertSame($proposal->proposal['operations'], $revised->proposal['operations']);
        $result = $service->apply($this->tree, $proposal, $proposal->treeEtag);
        self::assertIsArray($result['newPersonIds']);
        self::assertCount(1, $result['newPersonIds']);
        self::assertCount(2, $this->manualEvents());
    }

    public function testReviewsExistingPeopleRelationshipEditsAndRemovalsWithReadableSubjects(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $marriage = $em->getRepository(MarriagePeriod::class)->findOneBy(['tree' => $this->tree]);
        self::assertInstanceOf(MarriagePeriod::class, $marriage);
        $links = $em->getRepository(ParentLink::class)->findBy(['tree' => $this->tree]);
        $mother = $em->find(Person::class, $this->ids['mom']);
        self::assertInstanceOf(Person::class, $mother);
        $birth = static::getContainer()
            ->get(GenealogicalEventRepository::class)
            ->findAutomaticPersonLifecycle($mother, EventType::BIRTH);
        self::assertNotNull($birth);
        $operations = [
            [
                'type'     => 'update_person',
                'personId' => $this->ids['mom'],
                'fields'   => [
                    'givenName' => ['state' => 'known', 'value' => 'Mary'],
                ],
            ],
            ['type' => 'remove_parent_link', 'parentLinkId' => $links[0]->getId()->toRfc4122()],
            ['type' => 'delete_person', 'personId' => $this->ids['child']],
            [
                'type'       => 'update_marriage_period',
                'marriageId' => $marriage->getId()->toRfc4122(),
                'fields'     => ['marriageDate' => $this->date(1981)],
            ],
            [
                'type'    => 'set_event_place',
                'eventId' => $birth->getId()->toRfc4122(),
                'place'   => [
                    'name'        => 'Birth village',
                    'coordinates' => null,
                ],
            ],
        ];
        $service = static::getContainer()->get(TreeMergeService::class);
        $proposal = $service->prepare($this->tree, ['operations' => $operations], manual: true);
        self::assertSame('Mother', $mother->getGivenName()->getValue());
        self::assertIsArray($proposal->proposal['operationSubjects']);
        self::assertCount(5, $proposal->proposal['operationSubjects']);
        $service->apply($this->tree, $proposal, $proposal->treeEtag);
        self::assertSame('Mary', $mother->getGivenName()->getValue());
        self::assertSame('Birth village', $mother->getBirthPlace()?->getName());
    }

    public function testFinalLifeValidationAllowsChangingThePersonAndEventTogether(): void
    {
        $this->apply(
            [
                [
                    'type'      => 'create_events',
                    'personIds' => [$this->ids['child']],
                    'event'     => [
                        'type'        => 'custom',
                        'title'       => 'Celebration',
                        'description' => null,
                        'date'        => $this->date(2000),
                    ],
                ],
            ],
        );
        $id = $this->manualEvents()[0]->getId()->toRfc4122();
        $this->apply(
            [
                [
                    'type'     => 'update_person',
                    'personId' => $this->ids['child'],
                    'fields'   => ['birthDate' => $this->date(2005)],
                ],
                ['type' => 'update_event', 'eventId' => $id, 'fields' => ['date' => $this->date(2006)]],
            ],
        );
        self::assertSame(2006, $this->manualEvents()[0]->getDate()->getYear());
        $this->expectException(ApiException::class);
        $this->apply(
            [
                [
                    'type'     => 'update_person',
                    'personId' => $this->ids['child'],
                    'fields'   => ['birthDate' => $this->date(2007)],
                ],
            ],
        );
    }

    public function testInvalidLastParticipantLeavesNoPersonOrEventsPersisted(): void
    {
        $treeId = $this->tree->getId();
        $etag = static::getContainer()->get(TreeVersion::class)->etag($this->tree);
        try {
            $this->apply(
                [
                    ['type' => 'create_person', 'id' => 'late', 'fields' => ['birthDate' => $this->date(2020)]],
                    ['type' => 'add_parent_link', 'parent' => $this->ids['mom'], 'child' => 'late', 'role' => 'mother'],
                    [
                        'type'      => 'create_events',
                        'personIds' => [$this->ids['mom'], 'late'],
                        'event'     => [
                            'type'        => 'residence',
                            'date'        => $this->date(2000),
                            'description' => null,
                        ],
                    ],
                ],
            );
            self::fail('The last participant was not born yet.');
        } catch (ApiException $error) {
            self::assertSame('EVENT_DATA_INVALID', $error->getErrorCode());
        }
        $em = static::getContainer()->get(ManagerRegistry::class)->resetManager();
        self::assertSame(3, $em->getRepository(Person::class)->count(['tree' => $treeId]));
        self::assertSame(0, $em->getRepository(GenealogicalEvent::class)->count(['source' => EventSource::MANUAL]));
        $tree = $em->find(FamilyTree::class, $treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        self::assertSame($etag, static::getContainer()->get(TreeVersion::class)->etag($tree));
    }

    public function testCannotDirectlyEditAutomaticEventsOrUseForeignIds(): void
    {
        $events = static::getContainer()->get(GenealogicalEventRepository::class)->findAllForTree($this->tree);
        $invalid = [
            [['type' => 'delete_event', 'eventId' => $events[0]->getId()->toRfc4122()]],
            [
                [
                    'type'    => 'update_event',
                    'eventId' => $events[0]->getId()->toRfc4122(),
                    'fields'  => ['description' => 'Wrong'],
                ],
            ],
            [
                [
                    'type'     => 'update_person',
                    'personId' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
                    'fields'   => ['gender' => 'male'],
                ],
            ],
            [['type' => 'remove_parent_link', 'parentLinkId' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122()]],
            [
                [
                    'type'       => 'update_marriage_period',
                    'marriageId' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
                    'fields'     => ['marriageDate' => $this->date(1980)],
                ],
            ],
        ];
        foreach ($invalid as $operations) {
            try {
                $changes = static::getContainer()->get(TreeChangeSetFactory::class)->create($this->tree, $operations);
                static::getContainer()
                    ->get(TreeChangeSetProjector::class)
                    ->project(
                        $this->tree,
                        $changes,
                        new \DateTimeImmutable(),
                    );
                self::fail('Unavailable or automatic resource must be rejected.');
            } catch (ApiException $error) {
                self::assertContains($error->getStatus(), [404, 422]);
            }
        }
    }

    public function testReordersAmbiguousEventsAndUpdatesCanonicalAutomaticPlaces(): void
    {
        foreach (['First', 'Second'] as $title) {
            $this->apply(
                [
                    [
                        'type'      => 'create_events',
                        'personIds' => [$this->ids['child']],
                        'event'     => [
                            'type'        => 'custom',
                            'date'        => ['state' => 'unknown'],
                            'title'       => $title,
                            'description' => null,
                        ],
                    ],
                ],
            );
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $person = $em->find(Person::class, $this->ids['child']);
        self::assertInstanceOf(Person::class, $person);
        $timeline = static::getContainer()->get(\App\Application\Event\EventTimelineService::class)->forPerson($person);
        $ids = array_map(static fn (GenealogicalEvent $event): string => $event->getId()->toRfc4122(), $timeline);
        [$ids[1], $ids[2]] = [$ids[2], $ids[1]];
        $birth = static::getContainer()
            ->get(GenealogicalEventRepository::class)
            ->findAutomaticPersonLifecycle($person, EventType::BIRTH);
        self::assertNotNull($birth);
        $this->apply(
            [
                ['type' => 'reorder_events', 'personId' => $this->ids['child'], 'eventIds' => $ids],
                [
                    'type'    => 'set_event_place',
                    'eventId' => $birth->getId()->toRfc4122(),
                    'place'   => [
                        'name'        => 'Canonical birthplace',
                        'coordinates' => null,
                    ],
                ],
            ],
        );
        self::assertSame('Canonical birthplace', $person->getBirthPlace()?->getName());
        self::assertSame(
            $ids,
            array_map(
                static fn (GenealogicalEvent $event): string => $event->getId()->toRfc4122(),
                static::getContainer()->get(\App\Application\Event\EventTimelineService::class)->forPerson($person),
            ),
        );
    }

    public function testStalePacketDoesNotWriteOrCloseTheManagerNeededForReviewRefresh(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $etag = $container->get(TreeVersion::class)->etag($this->tree);
        $changes = $container
            ->get(TreeChangeSetFactory::class)
            ->create(
                $this->tree,
                [
                    [
                        'type'     => 'update_person',
                        'personId' => $this->ids['mom'],
                        'fields'   => ['givenName' => ['state' => 'known', 'value' => 'Must not save']],
                    ],
                ],
            );
        $this->tree->touch(new \DateTimeImmutable('+1 second'));
        $em->flush();
        try {
            $container->get(TreeChangeSetExecutor::class)->execute($this->tree, $changes, $etag);
            self::fail('A stale packet must conflict.');
        } catch (ApiException $error) {
            self::assertSame('TREE_VERSION_CONFLICT', $error->getErrorCode());
        }
        self::assertTrue($em->isOpen());
        $mother = $em->find(Person::class, $this->ids['mom']);
        self::assertInstanceOf(Person::class, $mother);
        self::assertSame('Mother', $mother->getGivenName()->getValue());
    }

    public function testLateFailureRollsBackEarlierWritesInTheTransaction(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $person = $em->find(Person::class, $this->ids['mom']);
        self::assertInstanceOf(Person::class, $person);
        $timeline = static::getContainer()->get(\App\Application\Event\EventTimelineService::class)->forPerson($person);
        $ids = array_map(static fn (GenealogicalEvent $event): string => $event->getId()->toRfc4122(), $timeline);
        $ids = array_reverse($ids);
        try {
            $this->apply(
                [
                    [
                        'type'     => 'update_person',
                        'personId' => $this->ids['mom'],
                        'fields'   => [
                            'givenName' => ['state' => 'known', 'value' => 'Must roll back'],
                        ],
                    ],
                    ['type' => 'reorder_events', 'personId' => $this->ids['mom'], 'eventIds' => $ids],
                ],
            );
            self::fail('Chronological order must be preserved.');
        } catch (ApiException $error) {
            self::assertSame('EVENT_ORDER_INVALID', $error->getErrorCode());
        }
        $em = static::getContainer()->get(ManagerRegistry::class)->resetManager();
        $restored = $em->find(Person::class, $this->ids['mom']);
        self::assertInstanceOf(Person::class, $restored);
        self::assertSame('Mother', $restored->getGivenName()->getValue());
    }

    /** @param list<array<string, mixed>> $operations
     * @return array{
     *     personIds: array<string, string>, newPersonIds: list<string>,
     *     affectedPersonIds: list<string>, etag: string
     * }
     */
    private function apply(array $operations): array
    {
        $container = static::getContainer();

        return $container
            ->get(TreeChangeSetExecutor::class)
            ->execute(
                $this->tree,
                $container->get(TreeChangeSetFactory::class)->create($this->tree, $operations),
                $container->get(TreeVersion::class)->etag($this->tree),
            );
    }

    /** @return array{state: string, kind: string, year: int} */
    private function date(int $year): array
    {
        return ['state' => 'known', 'kind' => 'exact', 'year' => $year];
    }

    /** @return list<GenealogicalEvent> */
    private function manualEvents(): array
    {
        return array_values(
            array_filter(
                static::getContainer()->get(GenealogicalEventRepository::class)->findAllForTree($this->tree),
                static fn (GenealogicalEvent $event): bool => EventSource::MANUAL === $event->getSource(),
            ),
        );
    }
}
