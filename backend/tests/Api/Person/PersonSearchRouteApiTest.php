<?php

namespace App\Tests\Api\Person;

use App\Entity\Person;
use App\Tests\Api\ApiWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-type TreeResponse array{id: string, etag: string}
 * @phpstan-type SnapshotPerson array{id: string}
 * @phpstan-type SnapshotParentLink array{id: string, parentId: string, childId: string, role: string}
 * @phpstan-type Snapshot array{etag: string, people: list<SnapshotPerson>, parentLinks: list<SnapshotParentLink>, marriages: list<array{id: string}>}
 * @phpstan-type SearchItem array{id: string, fullName: string, birthYear: int|null, deathYear: int|null}
 * @phpstan-type RouteNode array{personId: string, kind: 'route'|'support'}
 * @phpstan-type RoutePlan array{etag: string, targetPersonId: string, people: list<array{id: string}>, parentLinks: list<array{id: string}>, marriages: list<array{id: string}>, familyBlocks: list<array{id: string}>, nodes: list<RouteNode>}
 */
final class PersonSearchRouteApiTest extends ApiWebTestCase
{
    public function testSearchIsFuzzyLimitedAndIsolatedToTheTree(): void
    {
        $token = $this->register('search_owner');
        $tree = $this->createTree($token, 'Search');
        $snapshot = $this->firstPerson($token, $tree, $this->person([
            'birthSurname' => ['state' => 'known', 'value' => 'Ivanov'],
            'givenName'    => ['state' => 'known', 'value' => 'Ivan'],
            'patronymic'   => ['state' => 'known', 'value' => 'Ivanovich'],
            'birthDate'    => $this->date(2000),
        ]));
        $childId = $snapshot['people'][0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $childId,
        ]);
        $firstParent = $snapshot['parentLinks'][0]['parentId'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $firstParent,
        ]);
        $secondParent = $snapshot['parentLinks'][1]['parentId'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $secondParent,
        ]);

        foreach ($snapshot['people'] as $person) {
            $this->jsonRequest(
                'PATCH',
                sprintf('/api/v1/trees/%s/people/%s', $tree['id'], $person['id']),
                [
                    'birthSurname' => ['state' => 'known', 'value' => 'Ivanov'],
                    'givenName'    => ['state' => 'known', 'value' => 'Ivan'],
                ],
                [...$this->authorization($token), 'If-Match' => $snapshot['etag']],
            );
            self::assertResponseIsSuccessful();
            $nextEtag = $this->responseData()['etag'];
            if (!is_string($nextEtag)) {
                self::fail('Person update ETag must be a string.');
            }
            $snapshot['etag'] = $nextEtag;
        }

        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $tree['id'] . '/people/search?q=Ivanov%20Iva',
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var list<SearchItem> $items */
        $items = $this->responseData()['items'];
        self::assertCount(5, $items);
        self::assertSame('Ivanov Ivan Ivanovich', $items[0]['fullName']);
        self::assertArrayNotHasKey('uid', $items[0]);

        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $tree['id'] . '/people/search?q=Ivonov',
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var list<SearchItem> $items */
        $items = $this->responseData()['items'];
        self::assertNotSame([], $items);
        self::assertSame('Ivanov Ivan Ivanovich', $items[0]['fullName']);

        $otherTree = $this->createTree($token, 'Other');
        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $otherTree['id'] . '/people/search?q=Ivanov',
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseData()['items']);

        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $tree['id'] . '/people/search?q=I',
            headers: $this->authorization($token),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testSearchMatchesAOneCharacterCyrillicPrefix(): void
    {
        $token = $this->register('cyrillic_search_owner');
        $tree = $this->createTree($token, 'Cyrillic search');
        $snapshot = $this->firstPerson($token, $tree, $this->person([
            'birthSurname' => ['state' => 'known', 'value' => 'Савельев'],
        ]));

        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $tree['id'] . '/people/search?q=' . rawurlencode('Савелье'),
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var list<SearchItem> $items */
        $items = $this->responseData()['items'];
        self::assertCount(1, $items);
        self::assertSame($snapshot['people'][0]['id'], $items[0]['id']);
        self::assertSame('Савельев', $items[0]['fullName']);
    }

    public function testSearchDqlCompilesTheIndexableTrigramPrefilter(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $fullName = "TRIM(REGEXP_REPLACE_TEXT(LOWER(TRANSLATE_TEXT(CONCAT_WS(' ', person.birthSurname.value, person.givenName.value, person.patronymic.value), 'Ёё', 'Ее')), '[^[:alnum:]]+', ' ', 'g'))";
        $distance = 'LEAST_INTEGER(LEVENSHTEIN_LESS_EQUAL(:query, SUBSTRING(' . $fullName . ', 1, 255), :maximumDistance))';
        $sql = $entityManager->createQueryBuilder()
            ->select('person')
            ->addSelect($distance . ' AS HIDDEN distance')
            ->from(Person::class, 'person')
            ->where('STRICT_WORD_MATCH(:query, ' . $fullName . ') = true')
            ->andWhere('STRICT_WORD_SIMILARITY(:query, ' . $fullName . ') >= :minimumSimilarity')
            ->andWhere($distance . ' <= :maximumDistance')
            ->setParameter('query', 'ivanov')
            ->setParameter('maximumDistance', 1)
            ->setParameter('minimumSimilarity', 0.2)
            ->getQuery()
            ->getSQL();

        self::assertIsString($sql);
        self::assertStringContainsString(' <<% ', $sql);
        self::assertStringContainsString('strict_word_similarity', $sql);
        self::assertStringContainsString('levenshtein_less_equal', $sql);
        self::assertStringContainsString('concat_ws', $sql);
        self::assertStringContainsString('SUBSTRING(', $sql);
        self::assertStringContainsString('regexp_replace', $sql);
        self::assertStringContainsString('birth_surname_value', $sql);
        self::assertStringContainsString('given_name_value', $sql);
        self::assertStringContainsString('patronymic_value', $sql);
    }

    public function testRouteAddsBothParentsAndDetailsStayCompact(): void
    {
        $token = $this->register('route_owner');
        $tree = $this->createTree($token, 'Route');
        $snapshot = $this->firstPerson($token, $tree, $this->person([
            'birthSurname' => ['state' => 'known', 'value' => 'Child'],
            'birthDate'    => $this->date(2000),
        ]));
        $childId = $snapshot['people'][0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $childId,
            'father'  => $this->person([
                'birthSurname' => ['state' => 'known', 'value' => 'Father'],
                'gender'       => 'male',
                'birthDate'    => $this->date(1970),
            ]),
            'mother' => $this->person([
                'birthSurname' => ['state' => 'known', 'value' => 'Mother'],
                'gender'       => 'female',
                'birthDate'    => $this->date(1972),
            ]),
            'marriage' => null,
        ]);
        $motherId = $this->parentId($snapshot, $childId, 'mother');
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $motherId,
            'father'  => $this->person([
                'birthSurname' => ['state' => 'known', 'value' => 'Grandfather'],
                'gender'       => 'male',
                'birthDate'    => $this->date(1940),
            ]),
            'mother' => $this->person([
                'birthSurname' => ['state' => 'known', 'value' => 'Grandmother'],
                'gender'       => 'female',
                'birthDate'    => $this->date(1942),
            ]),
            'marriage' => null,
        ]);
        $grandmotherId = $this->parentId($snapshot, $motherId, 'mother');

        $this->jsonRequest(
            'GET',
            sprintf('/api/v1/trees/%s/route-to-reference/%s', $tree['id'], $grandmotherId),
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var RoutePlan $route */
        $route = $this->responseData();
        self::assertSame($snapshot['etag'], $route['etag']);
        self::assertSame($grandmotherId, $route['targetPersonId']);
        self::assertCount(5, $route['people']);
        self::assertCount(4, $route['parentLinks']);
        self::assertCount(2, $route['familyBlocks']);
        $nodes = [];
        foreach ($route['nodes'] as $node) {
            $nodes[$node['personId']] = $node['kind'];
        }
        self::assertSame('route', $nodes[$childId]);
        self::assertSame('route', $nodes[$motherId]);
        self::assertSame('route', $nodes[$grandmotherId]);
        self::assertCount(2, array_filter($nodes, static fn (string $kind): bool => 'support' === $kind));

        $this->jsonRequest(
            'GET',
            sprintf('/api/v1/trees/%s/people/%s/details', $tree['id'], $grandmotherId),
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var array{person: array{id: string}, permissions: array<string, mixed>, events: list<array<string, mixed>>, relationships: array{people: list<array<string, mixed>>, parentLinks: list<array<string, mixed>>, marriages: list<array<string, mixed>>}} $details */
        $details = $this->responseData();
        self::assertSame($grandmotherId, $details['person']['id']);
        self::assertArrayHasKey('permissions', $details);
        self::assertArrayHasKey('events', $details);
        self::assertArrayHasKey('relationships', $details);
        self::assertNotContains($grandmotherId, array_column($details['relationships']['people'], 'id'));
        self::assertCount(2, $details['relationships']['people']);
        foreach ($details['relationships']['people'] as $relatedPerson) {
            self::assertSame(
                ['id', 'birthSurname', 'givenName', 'patronymic', 'gender', 'birthDate', 'deathDate', 'photoThumbnailUrl'],
                array_keys($relatedPerson),
            );
        }
    }

    public function testMarriageReferenceUsesBothSpousesAndCollapsesRepeatedPeriods(): void
    {
        $token = $this->register('marriage_route_owner');
        $tree = $this->createTree($token, 'Marriage route');
        $snapshot = $this->firstPerson($token, $tree, $this->person([
            'gender' => 'male',
        ]));
        $firstId = $snapshot['people'][0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $firstId,
            'relative'         => ['person' => $this->person(['gender' => 'female'])],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $secondId = $this->otherPersonId($snapshot, $firstId);
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_child',
            'parents' => [
                ['personId' => $firstId, 'role' => 'father'],
                ['personId' => $secondId, 'role' => 'mother'],
            ],
            'relative' => ['person' => $this->person()],
        ]);
        $childId = $this->otherPersonId($snapshot, $firstId, $secondId);
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $firstId,
            'relative'         => ['personId' => $secondId],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $this->jsonRequest('PUT', '/api/v1/trees/' . $tree['id'] . '/reference', [
            'type'      => 'marriage',
            'personIds' => [$firstId, $secondId],
        ], [...$this->authorization($token), 'If-Match' => $snapshot['etag']]);
        self::assertResponseIsSuccessful();
        /** @var Snapshot $snapshot */
        $snapshot = $this->responseData();

        $this->jsonRequest(
            'GET',
            sprintf('/api/v1/trees/%s/route-to-reference/%s', $tree['id'], $childId),
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var RoutePlan $firstRoute */
        $firstRoute = $this->responseData();

        $this->jsonRequest(
            'GET',
            sprintf('/api/v1/trees/%s/route-to-reference/%s', $tree['id'], $childId),
            headers: $this->authorization($token),
        );
        self::assertResponseIsSuccessful();
        /** @var RoutePlan $secondRoute */
        $secondRoute = $this->responseData();

        self::assertSame($snapshot['etag'], $firstRoute['etag']);
        self::assertSame($firstRoute, $secondRoute);
        self::assertCount(2, $firstRoute['parentLinks']);
        self::assertCount(2, $firstRoute['marriages']);
        self::assertCount(1, $firstRoute['familyBlocks']);
        self::assertCount(2, array_filter(
            $firstRoute['nodes'],
            static fn (array $node): bool => 'route' === $node['kind'],
        ));
    }

    /** @return TreeResponse */
    private function createTree(string $token, string $name): array
    {
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => $name], $this->authorization($token));
        self::assertResponseStatusCodeSame(201);

        /** @var TreeResponse $tree */
        $tree = $this->responseData();

        return $tree;
    }

    /** @param array{id: string, etag: string} $tree
     * @param array<string, mixed> $person
     *
     * @return Snapshot
     */
    private function firstPerson(string $token, array $tree, array $person): array
    {
        return $this->mutate($token, $tree['id'], $tree['etag'], [
            'type' => 'create_first_person', 'person' => $person,
        ]);
    }

    /** @param array<string, mixed> $payload
     * @return Snapshot
     */
    private function mutate(string $token, string $treeId, string $etag, array $payload): array
    {
        $this->jsonRequest('POST', '/api/v1/trees/' . $treeId . '/graph-mutations', $payload, [
            ...$this->authorization($token), 'If-Match' => $etag,
        ]);
        self::assertResponseIsSuccessful();

        /** @var Snapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    /** @param Snapshot $snapshot */
    private function parentId(array $snapshot, string $childId, string $role): string
    {
        foreach ($snapshot['parentLinks'] as $link) {
            if ($link['childId'] === $childId && $link['role'] === $role) {
                return $link['parentId'];
            }
        }

        self::fail('Parent link not found.');
    }

    /** @param Snapshot $snapshot */
    private function otherPersonId(array $snapshot, string ...$excluded): string
    {
        foreach ($snapshot['people'] as $person) {
            if (!in_array($person['id'], $excluded, true)) {
                return $person['id'];
            }
        }

        self::fail('Person not found.');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return array_replace([
            'birthSurname' => ['state' => 'unknown'],
            'givenName'    => ['state' => 'unknown'],
            'patronymic'   => ['state' => 'unknown'],
            'gender'       => 'unknown',
            'birthDate'    => ['state' => 'unknown'],
            'deathDate'    => null,
        ], $overrides);
    }

    /** @return array{state: string, kind: string, year: int} */
    private function date(int $year): array
    {
        return ['state' => 'known', 'kind' => 'exact', 'year' => $year];
    }

    private function register(string $username): string
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration([
            'username' => $username,
            'email'    => $username . '@example.test',
        ]));
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }

    /** @return array{Authorization: string} */
    private function authorization(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
