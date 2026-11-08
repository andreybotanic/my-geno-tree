<?php

namespace App\Tests\Api\Tree;

use App\Entity\FamilyTree;
use App\Entity\TreeLayout;
use App\Repository\TreeLayoutRepository;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-type LayoutSnapshot array{
 *     tree: array{id: string, reference: array{type: string, personId?: string, personIds?: list<string>}|null},
 *     etag: string,
 *     layoutRevision: string|null,
 *     layout: array{algorithmVersion: int, positions: array<string, list<int|float>>}|null,
 *     people: list<array{id: string}>,
 *     parentLinks: list<array{id: string, parentId: string, childId: string}>,
 *     marriages: list<array{id: string}>
 * }
 */
final class TreeLayoutApiTest extends ApiWebTestCase
{
    public function testLayoutRoundTripDoesNotChangeTreeEtagAndRejectsInvalidInput(): void
    {
        $token = $this->register();
        $tree = $this->createTree($token, 'Layout tree');
        $snapshot = $this->mutate($token, $tree['id'], $tree['etag'], [
            'type'   => 'create_first_person',
            'person' => $this->person(),
        ]);
        $personId = $snapshot['people'][0]['id'];
        $revision = $snapshot['layoutRevision'];
        self::assertIsString($revision);
        self::assertSame(43, strlen($revision));
        self::assertNull($snapshot['layout']);

        $queryCounter = static::getContainer()->get(QueryCountingMiddleware::class);
        self::assertInstanceOf(QueryCountingMiddleware::class, $queryCounter);
        $queryCounter->reset();
        $uncached = $this->snapshot($token, $tree['id']);
        $uncachedQueryCount = $queryCounter->count();
        self::assertNull($uncached['layout']);
        self::assertLessThanOrEqual(10, $uncachedQueryCount);

        $this->saveLayout($token, $tree['id'], $revision, [$personId => [125, -75]]);
        self::assertResponseStatusCodeSame(204);

        $queryCounter->reset();
        $saved = $this->snapshot($token, $tree['id']);
        $cachedQueryCount = $queryCounter->count();
        self::assertLessThanOrEqual($uncachedQueryCount + 2, $cachedQueryCount);
        self::assertLessThanOrEqual(10, $cachedQueryCount);
        self::assertSame($snapshot['etag'], $saved['etag']);
        self::assertSame($revision, $saved['layoutRevision']);
        self::assertSame([
            'algorithmVersion' => 1,
            'positions'        => [$personId => [125, -75]],
        ], $saved['layout']);

        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $tree['id'],
            ['name'                             => 'Renamed without reflow'],
            [...$this->auth($token), 'If-Match' => $saved['etag']],
        );
        self::assertResponseIsSuccessful();
        $renamedEtag = $this->responseString('etag');
        self::assertNotSame($saved['etag'], $renamedEtag);

        $renamed = $this->snapshot($token, $tree['id']);
        self::assertSame($revision, $renamed['layoutRevision']);
        self::assertSame($saved['layout'], $renamed['layout']);

        $staleRevision = str_starts_with($revision, 'A')
            ? 'B' . substr($revision, 1)
            : 'A' . substr($revision, 1);
        $this->saveLayout($token, $tree['id'], $staleRevision, [$personId => [0, 0]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('LAYOUT_REVISION_CONFLICT', $this->responseString('code'));

        $this->saveLayout($token, $tree['id'], $revision, [
            $personId                              => [0, 0],
            '0198d4f6-a5a4-7c3e-a885-e0dc8f91bcaa' => [1, 1],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('LAYOUT_INVALID', $this->responseString('code'));

        $this->saveLayout('', $tree['id'], $revision, [$personId => [0, 0]]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testStructuralMutationInvalidatesOnlyAffectedReferenceLayout(): void
    {
        $token = $this->register();
        $tree = $this->createTree($token, 'Selective invalidation');
        $snapshot = $this->mutate($token, $tree['id'], $tree['etag'], [
            'type'   => 'create_first_person',
            'person' => $this->person('male'),
        ]);
        $husbandId = $snapshot['people'][0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $husbandId,
            'relative'         => ['person' => $this->person('female')],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $wifeId = array_values(array_filter(
            $snapshot['people'],
            static fn (array $person): bool => $person['id'] !== $husbandId,
        ))[0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $husbandId,
            'role'     => 'father',
            'relative' => ['person' => $this->person('male')],
        ]);
        $fatherId = array_values(array_filter(
            $snapshot['people'],
            static fn (array $person): bool => !in_array($person['id'], [$husbandId, $wifeId], true),
        ))[0]['id'];

        $husbandRevision = $snapshot['layoutRevision'];
        self::assertIsString($husbandRevision);
        $positions = $this->positions($snapshot['people']);
        $this->saveLayout($token, $tree['id'], $husbandRevision, $positions);
        self::assertResponseStatusCodeSame(204);

        $snapshot = $this->changeReference(
            $token,
            $tree['id'],
            $snapshot['etag'],
            $wifeId,
        );
        $wifeRevision = $snapshot['layoutRevision'];
        self::assertIsString($wifeRevision);
        $this->saveLayout($token, $tree['id'], $wifeRevision, $positions);
        self::assertResponseStatusCodeSame(204);

        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $husbandId,
            'relative'         => ['personId' => $wifeId],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => ['state' => 'unknown'],
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        self::assertSame($wifeRevision, $snapshot['layoutRevision']);
        self::assertSame($positions, $snapshot['layout']['positions'] ?? null);

        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $fatherId,
            'role'     => 'father',
            'relative' => ['person' => $this->person('male')],
        ]);
        self::assertCount(6, $snapshot['people']);
        self::assertSame($wifeRevision, $snapshot['layoutRevision']);
        self::assertSame($positions, $snapshot['layout']['positions'] ?? null);

        $snapshot = $this->changeReference(
            $token,
            $tree['id'],
            $snapshot['etag'],
            $husbandId,
        );
        self::assertNotSame($husbandRevision, $snapshot['layoutRevision']);
        self::assertNull($snapshot['layout']);
    }

    public function testEleventhAnchorEvictsTheLeastRecentlyUsedLayout(): void
    {
        $token = $this->register();
        $tree = $this->createTree($token, 'LRU tree');
        $snapshot = $this->mutate($token, $tree['id'], $tree['etag'], [
            'type'   => 'create_first_person',
            'person' => $this->person('male'),
        ]);
        $personIds = [$snapshot['people'][0]['id']];
        $childId = $personIds[0];
        for ($index = 1; $index <= 10; ++$index) {
            $knownIds = array_fill_keys(array_column($snapshot['people'], 'id'), true);
            $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
                'type'     => 'add_parent',
                'childId'  => $childId,
                'role'     => 'father',
                'relative' => ['person' => $this->person('male')],
            ]);
            $newPerson = array_values(array_filter(
                $snapshot['people'],
                static fn (array $person): bool => !isset($knownIds[$person['id']]),
            ))[0];
            $childId = $newPerson['id'];
            $personIds[] = $childId;
        }
        $positions = $this->positions($snapshot['people']);

        foreach (array_slice($personIds, 0, 10) as $personId) {
            $snapshot = $this->changeReference(
                $token,
                $tree['id'],
                $snapshot['etag'],
                $personId,
            );
            self::assertIsString($snapshot['layoutRevision']);
            $this->saveLayout(
                $token,
                $tree['id'],
                $snapshot['layoutRevision'],
                $positions,
            );
            self::assertResponseStatusCodeSame(204);
        }

        $repository = static::getContainer()->get(TreeLayoutRepository::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(TreeLayoutRepository::class, $repository);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $treeEntity = $entityManager->getReference(
            FamilyTree::class,
            Uuid::fromString($tree['id']),
        );
        self::assertInstanceOf(FamilyTree::class, $treeEntity);
        $layouts = $repository->findAllForTree($treeEntity);
        self::assertCount(10, $layouts);
        foreach ($layouts as $index => $layout) {
            $layout->replace(
                $layout->getLayoutRevision(),
                $layout->getAlgorithmVersion(),
                $layout->getPositions(),
                new \DateTimeImmutable(sprintf('2000-01-%02dT00:00:00+00:00', $index + 1)),
            );
        }
        $oldestId = $layouts[0]->getId()->toRfc4122();
        $entityManager->flush();

        $snapshot = $this->changeReference(
            $token,
            $tree['id'],
            $snapshot['etag'],
            $personIds[10],
        );
        self::assertIsString($snapshot['layoutRevision']);
        $this->saveLayout(
            $token,
            $tree['id'],
            $snapshot['layoutRevision'],
            $positions,
        );
        self::assertResponseStatusCodeSame(204);

        $remainingIds = array_map(
            static fn (TreeLayout $layout): string => $layout->getId()->toRfc4122(),
            $repository->findAllForTree($treeEntity),
        );
        self::assertCount(10, $remainingIds);
        self::assertNotContains($oldestId, $remainingIds);

        $queryCounter = static::getContainer()->get(QueryCountingMiddleware::class);
        self::assertInstanceOf(QueryCountingMiddleware::class, $queryCounter);
        $queryCounter->reset();
        $this->snapshot($token, $tree['id']);
        self::assertLessThanOrEqual(10, $queryCounter->count());

        $singleTree = $this->createTree($token, 'Single layout query count');
        $singleSnapshot = $this->mutate($token, $singleTree['id'], $singleTree['etag'], [
            'type'   => 'create_first_person',
            'person' => $this->person('male'),
        ]);
        $singlePersonId = $singleSnapshot['people'][0]['id'];
        self::assertIsString($singleSnapshot['layoutRevision']);
        $this->saveLayout(
            $token,
            $singleTree['id'],
            $singleSnapshot['layoutRevision'],
            [$singlePersonId => [0, 0]],
        );
        self::assertResponseStatusCodeSame(204);

        $queryCounter->reset();
        $this->mutate($token, $singleTree['id'], $singleSnapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $singlePersonId,
            'role'     => 'father',
            'relative' => ['person' => $this->person('male')],
        ]);
        $singleLayoutInvalidationQueries = $queryCounter->count();

        $queryCounter->reset();
        $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $childId,
            'role'     => 'father',
            'relative' => ['person' => $this->person('male')],
        ]);
        $tenLayoutInvalidationQueries = $queryCounter->count();
        self::assertLessThanOrEqual(
            $singleLayoutInvalidationQueries + 2,
            $tenLayoutInvalidationQueries,
        );
        self::assertCount(0, $repository->findAllForTree($treeEntity));
    }

    private function register(): string
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }

    /** @return array{id: string, etag: string} */
    private function createTree(string $token, string $name): array
    {
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => $name], $this->auth($token));
        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string, etag: string} $tree */
        $tree = $this->responseData();

        return $tree;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return LayoutSnapshot
     */
    private function mutate(string $token, string $treeId, string $etag, array $payload): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $treeId . '/graph-mutations',
            $payload,
            [...$this->auth($token), 'If-Match' => $etag],
        );
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201]);

        /** @var LayoutSnapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    /** @return LayoutSnapshot */
    private function snapshot(string $token, string $treeId): array
    {
        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $treeId . '/snapshot',
            headers: $this->auth($token),
        );
        self::assertResponseIsSuccessful();

        /** @var LayoutSnapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    /** @return LayoutSnapshot */
    private function changeReference(
        string $token,
        string $treeId,
        string $etag,
        string $personId,
    ): array {
        $this->jsonRequest(
            'PUT',
            '/api/v1/trees/' . $treeId . '/reference',
            ['type'                             => 'person', 'personId' => $personId],
            [...$this->auth($token), 'If-Match' => $etag],
        );
        self::assertResponseIsSuccessful();

        /** @var LayoutSnapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    /** @param array<string, list<int|float>> $positions */
    private function saveLayout(
        string $token,
        string $treeId,
        string $revision,
        array $positions,
    ): void {
        $headers = '' === $token ? [] : $this->auth($token);
        $this->jsonRequest(
            'PUT',
            '/api/v1/trees/' . $treeId . '/layout',
            [
                'layoutRevision'   => $revision,
                'algorithmVersion' => 1,
                'positions'        => $positions,
            ],
            $headers,
        );
    }

    /** @param list<array{id: string}> $people
     *
     * @return array<string, list<int>>
     */
    private function positions(array $people): array
    {
        $positions = [];
        foreach ($people as $index => $person) {
            $positions[$person['id']] = [$index * 10, $index * 20];
        }
        ksort($positions);

        return $positions;
    }

    /** @return array<string, mixed> */
    private function person(string $gender = 'unknown'): array
    {
        return [
            'birthSurname' => ['state' => 'unknown'],
            'givenName'    => ['state' => 'unknown'],
            'patronymic'   => ['state' => 'unknown'],
            'gender'       => $gender,
            'birthDate'    => ['state' => 'unknown'],
            'deathDate'    => null,
        ];
    }

    /** @return array{Authorization: string} */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
