<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class GraphMutationApiTest extends ApiWebTestCase
{
    public function testParentsAreCreatedAtomicallyWithRoleAndCycleRules(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson(
            $accessToken,
            $tree,
            $this->person([
                'birthDate' => $this->knownDate('year', 2000),
            ]),
        );
        $child = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_parent',
            'childId' => $child['id'],
            'role' => 'father',
            'relative' => [
                'person' => $this->person([
                    'birthDate' => $this->knownDate('year', 1970),
                ]),
                'coordinates' => ['x' => 0, 'y' => -220],
            ],
            'coordinateUpdates' => [[
                'personId' => $child['id'],
                'coordinates' => ['x' => 30, 'y' => 0],
            ]],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $snapshot['people']);
        self::assertCount(1, $snapshot['parentLinks']);
        $father = $this->personById(
            $snapshot,
            $snapshot['parentLinks'][0]['parentId'],
        );
        self::assertInstanceOf(
            UuidV7::class,
            Uuid::fromString($snapshot['parentLinks'][0]['id']),
        );
        self::assertSame('male', $father['gender']);
        self::assertSame('father', $snapshot['parentLinks'][0]['role']);
        self::assertSame(
            ['x' => 30, 'y' => 0],
            $this->personById($snapshot, $child['id'])['coordinates'],
        );
        $fatherEvents = $this->events(
            $accessToken,
            $tree['id'],
            $father['id'],
        );
        self::assertSame(
            ['birth', 'child_birth'],
            array_column($fatherEvents, 'type'),
        );
        self::assertSame(
            $child['id'],
            $fatherEvents[1]['data']['childId'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_parent',
            'childId' => $child['id'],
            'role' => 'father',
            'relative' => [
                'person' => $this->person(['gender' => 'male']),
                'coordinates' => ['x' => 240, 'y' => -220],
            ],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'PARENT_ROLE_ALREADY_ASSIGNED',
            $this->responseData()['code'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'link_parent_child',
            'parentId' => $child['id'],
            'childId' => $father['id'],
            'role' => 'mother',
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ANCESTOR_CYCLE', $this->responseData()['code']);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_parent',
            'childId' => $father['id'],
            'role' => 'mother',
            'relative' => [
                'person' => $this->person([
                    'gender' => 'female',
                    'birthDate' => $this->knownDate('year', 1980),
                ]),
                'coordinates' => ['x' => 0, 'y' => -440],
            ],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['event_before_person_birth'],
            $this->responseData()['fields']['child.birthDate'],
        );

        $current = $this->snapshot($accessToken, $tree['id']);
        self::assertCount(2, $current['people']);
        self::assertCount(1, $current['parentLinks']);
        self::assertSame('unknown', $this->personById(
            $current,
            $child['id'],
        )['gender']);
    }

    public function testMarriageAndTwoParentChildSynchronizeEvents(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson(
            $accessToken,
            $tree,
            $this->person([
                'gender' => 'male',
                'birthDate' => $this->knownDate('year', 1960),
            ]),
        );
        $male = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_marriage_period',
            'personId' => $male['id'],
            'relative' => [
                'person' => $this->person([
                    'gender' => 'female',
                    'birthDate' => $this->knownDate('year', 1962),
                ]),
                'coordinates' => ['x' => 240, 'y' => 0],
            ],
            'marriageDate' => $this->knownDate('year', 1985),
            'dissolutionDate' => null,
            'maleNewSurname' => null,
            'femaleNewSurname' => 'Petrova',
            'coordinateUpdates' => [],
        ]);
        $female = array_values(array_filter(
            $snapshot['people'],
            static fn (array $person): bool => $person['id'] !== $male['id'],
        ))[0];
        self::assertCount(1, $snapshot['marriages']);
        self::assertSame($male['id'], $snapshot['marriages'][0]['malePersonId']);
        self::assertSame(
            $female['id'],
            $snapshot['marriages'][0]['femalePersonId'],
        );

        $femaleEvents = $this->events(
            $accessToken,
            $tree['id'],
            $female['id'],
        );
        self::assertSame(
            ['birth', 'marriage', 'surname_change'],
            array_column($femaleEvents, 'type'),
        );
        self::assertSame(
            'Petrova',
            $femaleEvents[2]['data']['newSurname'],
        );

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_child',
            'parents' => [
                ['personId' => $male['id'], 'role' => 'father'],
                ['personId' => $female['id'], 'role' => 'mother'],
            ],
            'relative' => [
                'person' => $this->person([
                    'birthDate' => $this->knownDate('day', 1990, month: 5, day: 3),
                ]),
                'coordinates' => ['x' => 120, 'y' => 240],
            ],
            'coordinateUpdates' => [],
        ]);
        self::assertCount(3, $snapshot['people']);
        self::assertCount(2, $snapshot['parentLinks']);
        self::assertSame(
            ['father', 'mother'],
            array_column($snapshot['parentLinks'], 'role'),
        );
        $childIds = array_unique(array_column(
            $snapshot['parentLinks'],
            'childId',
        ));
        self::assertCount(1, $childIds);
        $childId = array_values($childIds)[0];
        self::assertSame(
            ['birth', 'marriage', 'child_birth'],
            array_column(
                $this->events($accessToken, $tree['id'], $male['id']),
                'type',
            ),
        );
        self::assertSame(
            ['birth', 'marriage', 'surname_change', 'child_birth'],
            array_column(
                $this->events($accessToken, $tree['id'], $female['id']),
                'type',
            ),
        );
        self::assertSame(
            $this->knownDate('day', 1990, month: 5, day: 3),
            $this->personById($snapshot, $childId)['birthDate'],
        );

    }

    public function testMarriagePatchUsesEtagAndPreservesSurnameEvent(): void
    {
        [$accessToken, $tree, $snapshot, $male, $female] =
            $this->marriedTree();
        $marriage = $snapshot['marriages'][0];

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/marriages/%s',
                $tree['id'],
                $marriage['id'],
            ),
            [
                'marriageDate' => $this->knownDate('month', 1986, month: 6),
                'dissolutionDate' => ['state' => 'unknown'],
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $snapshot['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        $result = $this->responseData();
        self::assertSame(
            $this->client->getResponse()->headers->get('ETag'),
            $result['etag'],
        );
        $updatedEtag = $result['etag'];
        self::assertIsString($updatedEtag);
        self::assertSame(
            ['state' => 'unknown'],
            $result['marriage']['dissolutionDate'],
        );
        self::assertEqualsCanonicalizing(
            ['birth', 'marriage', 'surname_change', 'divorce'],
            array_column(
                $this->events($accessToken, $tree['id'], $female['id']),
                'type',
            ),
        );

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/marriages/%s',
                $tree['id'],
                $marriage['id'],
            ),
            ['dissolutionDate' => null],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $updatedEtag,
            ],
        );
        self::assertResponseIsSuccessful();
        $latest = $this->responseData();
        $latestEtag = $latest['etag'];
        self::assertIsString($latestEtag);
        self::assertNull($latest['marriage']['dissolutionDate']);
        self::assertEqualsCanonicalizing(
            ['birth', 'marriage', 'surname_change'],
            array_column(
                $this->events($accessToken, $tree['id'], $female['id']),
                'type',
            ),
        );

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s',
                $tree['id'],
                $male['id'],
            ),
            ['gender' => 'male'],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $latestEtag,
            ],
        );
        self::assertResponseIsSuccessful();
        $sameGenderResult = $this->responseData();
        $sameGenderEtag = $sameGenderResult['etag'];
        self::assertIsString($sameGenderEtag);

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s',
                $tree['id'],
                $male['id'],
            ),
            ['gender' => 'female'],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $sameGenderEtag,
            ],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['gender_conflicts_with_marriage'],
            $this->responseData()['fields']['gender'],
        );

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/marriages/%s',
                $tree['id'],
                $marriage['id'],
            ),
            ['marriageDate' => $this->knownDate('year', 1900)],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $snapshot['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(412);
    }

    public function testRemovalAndDeletionPreserveConnectivity(): void
    {
        [$accessToken, $tree, $snapshot, $male, $female] =
            $this->marriedTree();
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_child',
            'parents' => [
                ['personId' => $male['id'], 'role' => 'father'],
                ['personId' => $female['id'], 'role' => 'mother'],
            ],
            'relative' => [
                'person' => $this->person(),
                'coordinates' => ['x' => 120, 'y' => 240],
            ],
            'coordinateUpdates' => [],
        ]);

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'remove_marriage_period',
            'marriageId' => $snapshot['marriages'][0]['id'],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $snapshot['marriages']);
        self::assertCount(2, $snapshot['parentLinks']);

        $fatherLink = array_values(array_filter(
            $snapshot['parentLinks'],
            static fn (array $link): bool => 'father' === $link['role'],
        ))[0];
        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'remove_parent_link',
            'parentLinkId' => $fatherLink['id'],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TREE_WOULD_DISCONNECT', $this->responseData()['code']);

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'delete_person',
            'personId' => $male['id'],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $snapshot['people']);
        self::assertCount(1, $snapshot['parentLinks']);
        self::assertSame(
            ['birth', 'child_birth'],
            array_column(
                $this->events($accessToken, $tree['id'], $female['id']),
                'type',
            ),
        );
    }

    public function testInternalPersonAndInvalidMutationsAreRejected(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson(
            $accessToken,
            $tree,
            $this->person(['gender' => 'male']),
        );
        $root = $snapshot['people'][0];
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_child',
            'parents' => [['personId' => $root['id'], 'role' => 'father']],
            'relative' => [
                'person' => $this->person(['gender' => 'male']),
                'coordinates' => ['x' => 0, 'y' => 220],
            ],
            'coordinateUpdates' => [],
        ]);
        $middle = $this->personById(
            $snapshot,
            $snapshot['parentLinks'][0]['childId'],
        );
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_child',
            'parents' => [['personId' => $middle['id'], 'role' => 'father']],
            'relative' => [
                'person' => $this->person(),
                'coordinates' => ['x' => 0, 'y' => 440],
            ],
            'coordinateUpdates' => [],
        ]);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'delete_person',
            'personId' => $middle['id'],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'INTERNAL_PERSON_CANNOT_BE_DELETED',
            $this->responseData()['code'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_child',
            'parents' => [
                ['personId' => $root['id'], 'role' => 'father'],
                ['personId' => $middle['id'], 'role' => 'father'],
            ],
            'relative' => ['personId' => $root['id']],
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['parents_must_be_distinct'],
            $this->responseData()['fields']['parents'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'link_parent_child',
            'parentId' => $root['id'],
            'childId' => $middle['id'],
            'role' => 'father',
            'coordinateUpdates' => [
                [
                    'personId' => $root['id'],
                    'coordinates' => ['x' => 1, 'y' => 1],
                ],
                [
                    'personId' => $root['id'],
                    'coordinates' => ['x' => 2, 'y' => 2],
                ],
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'PARENT_LINK_ALREADY_EXISTS',
            $this->responseData()['code'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_marriage_period',
            'personId' => $root['id'],
            'relative' => ['personId' => $middle['id']],
            'marriageDate' => ['state' => 'unknown'],
            'dissolutionDate' => null,
            'maleNewSurname' => null,
            'femaleNewSurname' => null,
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['marriage_gender_pair_invalid'],
            $this->responseData()['fields']['personId'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_marriage_period',
            'personId' => $root['id'],
            'relative' => [
                'person' => $this->person(),
                'coordinates' => ['x' => -240, 'y' => 0],
            ],
            'marriageDate' => ['state' => 'unknown'],
            'dissolutionDate' => null,
            'maleNewSurname' => null,
            'femaleNewSurname' => null,
            'coordinateUpdates' => [],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['marriage_gender_required'],
            $this->responseData()['fields']['personId'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'not_a_mutation',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['graph_mutation_type_invalid'],
            $this->responseData()['fields']['type'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'unexpected' => true,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['graph_mutation_type_invalid'],
            $this->responseData()['fields']['type'],
        );
    }

    public function testCurrentSpouseWithCommonChildCannotBeDeleted(): void
    {
        [$accessToken, $tree, $snapshot, $male, $female] =
            $this->marriedTree();
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_child',
            'parents' => [
                ['personId' => $male['id'], 'role' => 'father'],
                ['personId' => $female['id'], 'role' => 'mother'],
            ],
            'relative' => [
                'person' => $this->person(),
                'coordinates' => ['x' => 120, 'y' => 240],
            ],
            'coordinateUpdates' => [],
        ]);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'delete_person',
            'personId' => $male['id'],
            'coordinateUpdates' => [],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'CURRENT_SPOUSE_COMMON_CHILD',
            $this->responseData()['code'],
        );
    }

    /**
     * @return array{
     *     string,
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     array<string, mixed>
     * }
     */
    private function marriedTree(): array
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson(
            $accessToken,
            $tree,
            $this->person([
                'gender' => 'male',
                'birthDate' => $this->knownDate('year', 1960),
            ]),
        );
        $male = $snapshot['people'][0];
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'add_marriage_period',
            'personId' => $male['id'],
            'relative' => [
                'person' => $this->person([
                    'gender' => 'female',
                    'birthDate' => $this->knownDate('year', 1962),
                ]),
                'coordinates' => ['x' => 240, 'y' => 0],
            ],
            'marriageDate' => $this->knownDate('year', 1985),
            'dissolutionDate' => null,
            'maleNewSurname' => null,
            'femaleNewSurname' => 'Petrova',
            'coordinateUpdates' => [],
        ]);
        $female = array_values(array_filter(
            $snapshot['people'],
            static fn (array $person): bool => $person['id'] !== $male['id'],
        ))[0];

        return [$accessToken, $tree, $snapshot, $male, $female];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function mutate(
        string $accessToken,
        string $treeId,
        string $etag,
        array $payload,
    ): array {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/'.$treeId.'/graph-mutations',
            $payload,
            [
                ...$this->authorization($accessToken),
                'If-Match' => $etag,
            ],
        );

        return $this->responseData();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $accessToken, string $treeId): array
    {
        $this->jsonRequest(
            'GET',
            '/api/v1/trees/'.$treeId.'/snapshot',
            headers: $this->authorization($accessToken),
        );
        self::assertResponseIsSuccessful();

        return $this->responseData();
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    private function firstPerson(
        string $accessToken,
        array $tree,
        array $person,
    ): array {
        return $this->mutate($accessToken, $tree['id'], $tree['etag'], [
            'type' => 'create_first_person',
            'person' => $person,
            'coordinates' => ['x' => 0, 'y' => 0],
        ]);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function personById(array $snapshot, string $id): array
    {
        foreach ($snapshot['people'] as $person) {
            if ($person['id'] === $id) {
                return $person;
            }
        }

        self::fail('Person not found in snapshot.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(
        string $accessToken,
        string $treeId,
        string $personId,
    ): array {
        $this->jsonRequest(
            'GET',
            sprintf(
                '/api/v1/trees/%s/people/%s/events',
                $treeId,
                $personId,
            ),
            headers: $this->authorization($accessToken),
        );
        self::assertResponseIsSuccessful();

        return $this->responseData()['items'];
    }

    private function register(): string
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->responseData()['accessToken'];
    }

    /**
     * @return array<string, mixed>
     */
    private function createTree(string $accessToken): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees',
            ['name' => 'History'],
            $this->authorization($accessToken),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->responseData();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return array_replace([
            'birthSurname' => ['state' => 'unknown'],
            'givenName' => ['state' => 'unknown'],
            'patronymic' => ['state' => 'unknown'],
            'gender' => 'unknown',
            'birthDate' => ['state' => 'unknown'],
            'deathDate' => null,
        ], $overrides);
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function knownDate(
        string $precision,
        int $year,
        bool $approximate = false,
        ?int $month = null,
        ?int $day = null,
    ): array {
        $date = [
            'state' => 'known',
            'precision' => $precision,
            'year' => $year,
            'approximate' => $approximate,
        ];
        if (null !== $month) {
            $date['month'] = $month;
        }
        if (null !== $day) {
            $date['day'] = $day;
        }

        return $date;
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorization(string $accessToken): array
    {
        return ['Authorization' => 'Bearer '.$accessToken];
    }
}
