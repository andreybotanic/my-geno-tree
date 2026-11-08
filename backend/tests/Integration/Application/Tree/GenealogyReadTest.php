<?php

namespace App\Tests\Integration\Application\Tree;

use App\Application\Tree\TreeReadService;
use App\Application\Tree\TreeVersion;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\DateKind;
use App\Entity\Value\EventType;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Repository\GenealogicalEventRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\PersonRepository;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Doctrine\ORM\EntityManagerInterface;

final class GenealogyReadTest extends ApiWebTestCase
{
    private FamilyTree $tree;
    /** @var list<string> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $owner = new User('read_records', 'records@example.test', 'hash', 'en', $now);
        $this->tree = new FamilyTree($owner, 'Read records', $now);
        $em->persist($owner);
        $em->persist($this->tree);
        $people = $this->ids = [];
        for ($index = 0; $index < 12; ++$index) {
            $birth = 0 === $index ? GenealogicalDate::known(DateKind::EXACT, 1900, 2, 1)
                : GenealogicalDate::known(DateKind::BETWEEN, 1920, 5, 2, 1925, 7, 10);
            $person = new Person(
                $this->tree,
                KnownText::unknown(),
                KnownText::known('Person ' . $index),
                KnownText::unknown(),
                0 === $index ? Gender::FEMALE : Gender::MALE,
                $birth,
                0 === $index ? GenealogicalDate::unknown() : null,
                $now,
            );
            $em->persist($person);
            $people[] = $person;
            $this->ids[] = $person->getId()->toRfc4122();
            if (0 === $index) {
                $place = EventPlace::resolved('Birth village', 54.0, 83.0);
                $em->persist($place);
                $person->changeBirthPlace($place, $now);
                $this->tree->referencePerson($person);
            } elseif ($index > 1) {
                $em->persist(new ParentLink($this->tree, $people[0], $person, ParentRole::MOTHER));
            }
            $em->persist(
                GenealogicalEvent::manual(
                    $person,
                    EventType::CUSTOM,
                    GenealogicalDate::unknown(),
                    'Full event',
                    'Complete description',
                    ['state' => 'unknown', 'note' => 'Preserve the entire JSON payload'],
                    $now,
                ),
            );
        }
        foreach ([null, GenealogicalDate::unknown()] as $end) {
            $em->persist(
                new MarriagePeriod(
                    $this->tree,
                    $people[1],
                    $people[0],
                    GenealogicalDate::unknown(),
                    $end,
                    $now,
                ),
            );
        }
        $em->flush();
    }

    public function testHydratesDomainFieldsWithoutLeakingStorageFlagsOrChangingEventJson(): void
    {
        $container = static::getContainer();
        $people = $container->get(PersonRepository::class);
        $mother = $people->readPage($this->tree, [$this->ids[0]], PersonRepository::READ_GROUPS, null, 10)['items'][0];
        self::assertSame(['state' => 'unknown'], $mother['birthSurname']);
        self::assertSame(['state' => 'unknown'], $mother['deathDate']);
        self::assertSame('female', $mother['gender']);
        self::assertSame('2026-01-02T03:04:05+00:00', $mother['createdAt']);
        self::assertSame(
            ['name' => 'Birth village', 'coordinates' => ['latitude' => 54.0, 'longitude' => 83.0]],
            $mother['birthPlace'],
        );
        self::assertArrayNotHasKey('deceased', $mother);
        self::assertArrayNotHasKey('storedDeathDate', $mother);
        self::assertArrayNotHasKey('photoAttachment', $mother);
        $child = $people->readPage($this->tree, [$this->ids[2]], ['life'], null, 10)['items'][0];
        self::assertSame(['id', 'birthDate', 'deathDate'], array_keys($child));
        self::assertNull($child['deathDate']);
        self::assertSame(
            [
                'state' => 'known',
                'kind'  => 'between',
                'year'  => 1920,
                'month' => 5,
                'day'   => 2,
                'end'   => ['year' => 1925, 'month' => 7, 'day' => 10],
            ],
            $child['birthDate'],
        );
        $marriages = $container->get(MarriagePeriodRepository::class)->readPage($this->tree, null, null, 10);
        self::assertNull($marriages['items'][0]['dissolutionDate']);
        self::assertSame(['state' => 'unknown'], $marriages['items'][1]['dissolutionDate']);
        self::assertArrayNotHasKey('dissolved', $marriages['items'][0]);
        $events = $container->get(GenealogicalEventRepository::class)->readPage(
            $this->tree,
            [$this->ids[0]],
            ['custom'],
            'manual',
            null,
            10,
        );
        self::assertSame('Complete description', $events['items'][0]['description']);
        self::assertSame(
            ['state' => 'unknown', 'note' => 'Preserve the entire JSON payload'],
            $events['items'][0]['data'],
        );
    }

    public function testEntityRepositoryPagesHaveConstantQueryCountAndDoNotManageReadPeople(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $treeId = $this->tree->getId();
        $em->clear();
        $tree = $em->find(FamilyTree::class, $treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        $identities = array_map(count(...), $em->getUnitOfWork()->getIdentityMap());
        $counter = $container->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $repository = $container->get(PersonRepository::class);
        foreach ([1, 200] as $limit) {
            $counter->reset();
            $page = $repository->readPage($tree, null, PersonRepository::READ_GROUPS, null, $limit);
            self::assertSame(12, $page['total']);
            self::assertCount(min($limit, 12), $page['items']);
            self::assertSame(2, $counter->selectCount());
            self::assertSame($identities, array_map(count(...), $em->getUnitOfWork()->getIdentityMap()));
        }
    }

    public function testAllKindsKeepStableKeysetPagesAndEmptySelections(): void
    {
        $container = static::getContainer();
        $reads = $container->get(TreeReadService::class);
        $etag = $container->get(TreeVersion::class)->etag($this->tree);
        $readers = [
            'people' => fn (?string $cursor): array => $reads->people(
                $this->tree,
                cursor: $cursor,
                limit: 3,
                expectedEtag: $etag,
            ),
            'parents' => fn (?string $cursor): array => $reads->parents(
                $this->tree,
                cursor: $cursor,
                limit: 3,
                expectedEtag: $etag,
            ),
            'marriages' => fn (?string $cursor): array => $reads->marriages(
                $this->tree,
                cursor: $cursor,
                limit: 3,
                expectedEtag: $etag,
            ),
            'events' => fn (?string $cursor): array => $reads->events(
                $this->tree,
                cursor: $cursor,
                limit: 3,
                expectedEtag: $etag,
            ),
        ];
        foreach ($readers as $kind => $read) {
            $seen = [];
            $cursor = null;
            do {
                $page = $read($cursor);
                self::assertIsArray($page['items']);
                foreach ($page['items'] as $item) {
                    self::assertIsArray($item);
                    self::assertIsString($item['id']);
                    self::assertNotContains($item['id'], $seen);
                    $seen[] = $item['id'];
                }
                $cursor = $page['nextCursor'];
                self::assertTrue(null === $cursor || is_string($cursor));
            } while (null !== $cursor);
            self::assertSame($page['total'], count($seen), $kind);
        }
        foreach ([
            $reads->people($this->tree, personIds: []),
            $reads->parents($this->tree, personIds: []),
            $reads->marriages($this->tree, personIds: []),
            $reads->events($this->tree, personIds: []),
            $reads->events($this->tree, types: []),
        ] as $empty) {
            self::assertSame(0, $empty['total']);
            self::assertSame([], $empty['items']);
            self::assertNull($empty['nextCursor']);
        }
    }
}
