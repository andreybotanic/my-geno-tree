<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @phpstan-type GraphTree array{id: string, etag: string}
 * @phpstan-type GraphPerson array{
 *     id: string,
 *     gender: string,
 *     birthSurname: array<string, mixed>,
 *     givenName: array<string, mixed>,
 *     birthDate: array<string, bool|int|string>
 * }
 * @phpstan-type GraphParentLink array{id: string, parentId: string, childId: string, role: string}
 * @phpstan-type GraphMarriage array{
 *     id: string,
 *     malePersonId: string,
 *     femalePersonId: string,
 *     marriagePlace: array{name: string, coordinates: array{latitude: float, longitude: float}|null}|null,
 *     dissolutionPlace: array{name: string, coordinates: array{latitude: float, longitude: float}|null}|null,
 *     dissolutionDate: array<string, mixed>|null
 * }
 * @phpstan-type GraphSnapshot array{
 *     etag: string,
 *     people: list<GraphPerson>,
 *     parentLinks: list<GraphParentLink>,
 *     marriages: list<GraphMarriage>
 * }
 * @phpstan-type GraphEvent array{
 *     type: string,
 *     place: array{name: string, coordinates: array{latitude: float, longitude: float}|null}|null,
 *     data: array{childId?: string, newSurname?: string}
 * }
 */
final class GraphMutationApiTest extends ApiWebTestCase
{
    public function testBothAnonymousParentsAreCreatedAtomically(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson($accessToken, $tree, $this->person());
        $child = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $child['id'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $snapshot['people']);
        self::assertCount(2, $snapshot['parentLinks']);
        self::assertSame([], $snapshot['marriages']);
        $parents = [];
        foreach ($snapshot['parentLinks'] as $link) {
            self::assertSame($child['id'], $link['childId']);
            $parents[$link['role']] = $this->personById($snapshot, $link['parentId']);
        }
        self::assertSame('male', $parents['father']['gender']);
        self::assertSame('female', $parents['mother']['gender']);
        self::assertSame(['state' => 'unknown'], $parents['father']['birthSurname']);
        self::assertSame(['state' => 'unknown'], $parents['mother']['givenName']);
        self::assertSame(['state' => 'unknown'], $parents['father']['birthDate']);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $child['id'],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('PARENT_ROLE_ALREADY_ASSIGNED', $this->responseString('code'));

        $current = $this->snapshot($accessToken, $tree['id']);
        self::assertCount(3, $current['people']);
        self::assertCount(2, $current['parentLinks']);
    }

    public function testDetailedParentsAreCreatedWithoutMarriage(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson($accessToken, $tree, $this->person([
            'birthDate' => $this->knownDate('year', 2000),
        ]));
        $child = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $child['id'],
            'father'  => $this->person([
                'birthSurname' => ['state' => 'known', 'value' => 'Petrov'],
                'givenName'    => ['state' => 'known', 'value' => 'Ivan'],
                'gender'       => 'male',
                'birthDate'    => $this->knownDate('year', 1970),
            ]),
            'mother' => $this->person([
                'birthSurname' => ['state' => 'known', 'value' => 'Sidorova'],
                'givenName'    => ['state' => 'known', 'value' => 'Anna'],
                'gender'       => 'female',
                'birthDate'    => $this->knownDate('year', 1972),
            ]),
            'marriage' => null,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $snapshot['people']);
        self::assertCount(2, $snapshot['parentLinks']);
        self::assertSame([], $snapshot['marriages']);
        $parents = [];
        foreach ($snapshot['parentLinks'] as $link) {
            $parents[$link['role']] = $this->personById($snapshot, $link['parentId']);
        }
        self::assertSame(
            ['state' => 'known', 'value' => 'Petrov'],
            $parents['father']['birthSurname'],
        );
        self::assertSame(
            ['state' => 'known', 'value' => 'Anna'],
            $parents['mother']['givenName'],
        );
    }

    public function testDetailedParentsMarriageSynchronizesEvents(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson($accessToken, $tree, $this->person([
            'birthDate' => $this->knownDate('year', 2000),
        ]));
        $child = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $child['id'],
            'father'  => $this->person([
                'gender'    => 'male',
                'birthDate' => $this->knownDate('year', 1960),
            ]),
            'mother' => $this->person([
                'gender'    => 'female',
                'birthDate' => $this->knownDate('year', 1962),
            ]),
            'marriage' => [
                'marriageDate'    => $this->knownDate('year', 1985),
                'dissolutionDate' => $this->knownDate('year', 2010),
                'marriagePlace'   => [
                    'name'        => 'Moscow',
                    'coordinates' => ['latitude' => 55.7558, 'longitude' => 37.6173],
                ],
                'dissolutionPlace' => [
                    'name'        => 'Novosibirsk',
                    'coordinates' => ['latitude' => 55.0302, 'longitude' => 82.9204],
                ],
                'maleNewSurname'   => 'Newman',
                'femaleNewSurname' => 'Newwoman',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $snapshot['marriages']);
        $parents = [];
        foreach ($snapshot['parentLinks'] as $link) {
            $parents[$link['role']] = $this->personById($snapshot, $link['parentId']);
        }
        self::assertSame('Moscow', $snapshot['marriages'][0]['marriagePlace']['name'] ?? null);
        self::assertSame('Novosibirsk', $snapshot['marriages'][0]['dissolutionPlace']['name'] ?? null);
        $fatherEvents = $this->events($accessToken, $tree['id'], $parents['father']['id']);
        $motherEvents = $this->events($accessToken, $tree['id'], $parents['mother']['id']);
        self::assertSame(
            ['birth', 'marriage', 'surname_change', 'child_birth', 'divorce'],
            array_column($fatherEvents, 'type'),
        );
        self::assertSame(
            ['birth', 'marriage', 'surname_change', 'child_birth', 'divorce'],
            array_column($motherEvents, 'type'),
        );
        foreach ([$fatherEvents, $motherEvents] as $events) {
            $placesByType = [];
            foreach ($events as $event) {
                $placesByType[$event['type']] = $event['place'];
            }
            self::assertSame('Moscow', $placesByType['marriage']['name'] ?? null);
            self::assertSame('Novosibirsk', $placesByType['divorce']['name'] ?? null);
        }
    }

    public function testInvalidDetailedParentsMarriageRollsBackEverything(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson($accessToken, $tree, $this->person([
            'birthDate' => $this->knownDate('year', 2000),
        ]));
        $child = $snapshot['people'][0];

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $child['id'],
            'father'  => $this->person([
                'gender'    => 'male',
                'birthDate' => $this->knownDate('year', 1960),
            ]),
            'mother' => $this->person([
                'gender'    => 'female',
                'birthDate' => $this->knownDate('year', 1962),
            ]),
            'marriage' => [
                'marriageDate'     => $this->knownDate('year', 1990),
                'dissolutionDate'  => $this->knownDate('year', 1980),
                'maleNewSurname'   => null,
                'femaleNewSurname' => null,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $current = $this->snapshot($accessToken, $tree['id']);
        self::assertSame($snapshot['etag'], $current['etag']);
        self::assertCount(1, $current['people']);
        self::assertSame([], $current['parentLinks']);
        self::assertSame([], $current['marriages']);
    }

    public function testInvalidDetailedParentRollsBackEverything(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson($accessToken, $tree, $this->person());
        $child = $snapshot['people'][0];

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parents',
            'childId'  => $child['id'],
            'father'   => $this->person(['gender' => 'female']),
            'mother'   => $this->person(['gender' => 'female']),
            'marriage' => null,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['parent_role_gender_conflict'],
            $this->responseStringList('fields', 'father.gender'),
        );
        $current = $this->snapshot($accessToken, $tree['id']);
        self::assertSame($snapshot['etag'], $current['etag']);
        self::assertCount(1, $current['people']);
        self::assertSame([], $current['parentLinks']);
        self::assertSame([], $current['marriages']);
    }

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
            'type'     => 'add_parent',
            'childId'  => $child['id'],
            'role'     => 'father',
            'relative' => [
                'person' => $this->person([
                    'birthDate' => $this->knownDate('year', 1970),
                ]),
            ],
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
        $fatherEvents = $this->events(
            $accessToken,
            $tree['id'],
            $father['id'],
        );
        self::assertSame(
            ['birth', 'child_birth'],
            array_column($fatherEvents, 'type'),
        );
        $childBirthData = $fatherEvents[1]['data'];
        if (!array_key_exists('childId', $childBirthData)) {
            self::fail('The child birth event must reference the child.');
        }
        self::assertSame(
            $child['id'],
            $childBirthData['childId'],
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $child['id'],
            'role'     => 'father',
            'relative' => [
                'person' => $this->person(['gender' => 'male']),
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'PARENT_ROLE_ALREADY_ASSIGNED',
            $this->responseString('code'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'link_parent_child',
            'parentId' => $child['id'],
            'childId'  => $father['id'],
            'role'     => 'mother',
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ANCESTOR_CYCLE', $this->responseString('code'));

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $father['id'],
            'role'     => 'mother',
            'relative' => [
                'person' => $this->person([
                    'gender'    => 'female',
                    'birthDate' => $this->knownDate('year', 1980),
                ]),
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['event_before_person_birth'],
            $this->responseStringList('fields', 'child.birthDate'),
        );

        $current = $this->snapshot($accessToken, $tree['id']);
        self::assertCount(2, $current['people']);
        self::assertCount(1, $current['parentLinks']);
        self::assertSame('unknown', $this->personById(
            $current,
            $child['id'],
        )['gender']);
    }

    public function testUnmarriedParentsAndOverlappingMarriagePeriodsAreSupported(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson(
            $accessToken,
            $tree,
            $this->person(),
        );
        $child = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $child['id'],
            'role'     => 'father',
            'relative' => [
                'person' => $this->person(),
            ],
        ]);
        $father = $this->personById(
            $snapshot,
            array_values(array_filter(
                $snapshot['parentLinks'],
                static fn (array $link): bool => 'father' === $link['role'],
            ))[0]['parentId'],
        );

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $child['id'],
            'role'     => 'mother',
            'relative' => [
                'person' => $this->person(),
            ],
        ]);
        $mother = $this->personById(
            $snapshot,
            array_values(array_filter(
                $snapshot['parentLinks'],
                static fn (array $link): bool => 'mother' === $link['role'],
            ))[0]['parentId'],
        );

        self::assertCount(2, $snapshot['parentLinks']);
        self::assertSame([], $snapshot['marriages']);
        self::assertSame('male', $father['gender']);
        self::assertSame('female', $mother['gender']);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'link_parent_child',
            'parentId' => $mother['id'],
            'childId'  => $father['id'],
            'role'     => 'father',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['parent_role_gender_conflict'],
            $this->responseStringList('fields', 'role'),
        );

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $father['id'],
            'relative'         => ['personId' => $mother['id']],
            'marriageDate'     => $this->knownDate('year', 1980),
            'dissolutionDate'  => $this->knownDate('year', 2000),
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $father['id'],
            'relative'         => ['personId' => $mother['id']],
            'marriageDate'     => $this->knownDate('year', 1990),
            'dissolutionDate'  => $this->knownDate('year', 2010),
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_marriage_period',
            'personId' => $father['id'],
            'relative' => [
                'person' => $this->person(['gender' => 'female']),
            ],
            'marriageDate'     => $this->knownDate('year', 1995),
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);

        self::assertCount(3, $snapshot['marriages']);
        self::assertCount(2, array_filter(
            $snapshot['marriages'],
            static fn (array $marriage): bool => $mother['id']
                === $marriage['femalePersonId'],
        ));
        self::assertCount(2, array_unique(array_column(
            $snapshot['marriages'],
            'femalePersonId',
        )));
    }

    public function testMarriageAndTwoParentChildSynchronizeEvents(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $snapshot = $this->firstPerson(
            $accessToken,
            $tree,
            $this->person([
                'gender'    => 'male',
                'birthDate' => $this->knownDate('year', 1960),
            ]),
        );
        $male = $snapshot['people'][0];

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_marriage_period',
            'personId' => $male['id'],
            'relative' => [
                'person' => $this->person([
                    'gender'    => 'female',
                    'birthDate' => $this->knownDate('year', 1962),
                ]),
            ],
            'marriageDate'     => $this->knownDate('year', 1985),
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => 'Petrova',
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
        $surnameChangeData = $femaleEvents[2]['data'];
        if (!array_key_exists('newSurname', $surnameChangeData)) {
            self::fail('The surname change event must contain a surname.');
        }
        self::assertSame(
            'Petrova',
            $surnameChangeData['newSurname'],
        );

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_child',
            'parents' => [
                ['personId' => $male['id'], 'role' => 'father'],
                ['personId' => $female['id'], 'role' => 'mother'],
            ],
            'relative' => [
                'person' => $this->person([
                    'birthDate' => $this->knownDate('day', 1990, month: 5, day: 3),
                ]),
            ],
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
                'marriageDate'    => $this->knownDate('month', 1986, month: 6),
                'dissolutionDate' => ['state' => 'unknown'],
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $snapshot['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var array{etag: string, marriage: GraphMarriage} $result */
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
        /** @var array{etag: string, marriage: GraphMarriage} $latest */
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
        /** @var array{etag: string} $sameGenderResult */
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
            $this->responseStringList('fields', 'gender'),
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
            'type'    => 'add_child',
            'parents' => [
                ['personId' => $male['id'], 'role' => 'father'],
                ['personId' => $female['id'], 'role' => 'mother'],
            ],
            'relative' => [
                'person' => $this->person(),
            ],
        ]);

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'       => 'remove_marriage_period',
            'marriageId' => $snapshot['marriages'][0]['id'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $snapshot['marriages']);
        self::assertCount(2, $snapshot['parentLinks']);

        $fatherLink = array_values(array_filter(
            $snapshot['parentLinks'],
            static fn (array $link): bool => 'father' === $link['role'],
        ))[0];
        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'         => 'remove_parent_link',
            'parentLinkId' => $fatherLink['id'],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TREE_WOULD_DISCONNECT', $this->responseString('code'));

        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'delete_person',
            'personId' => $male['id'],
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
            'type'     => 'add_child',
            'parents'  => [['personId' => $root['id'], 'role' => 'father']],
            'relative' => [
                'person' => $this->person(['gender' => 'male']),
            ],
        ]);
        $middle = $this->personById(
            $snapshot,
            $snapshot['parentLinks'][0]['childId'],
        );
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_child',
            'parents'  => [['personId' => $middle['id'], 'role' => 'father']],
            'relative' => [
                'person' => $this->person(),
            ],
        ]);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'delete_person',
            'personId' => $middle['id'],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'INTERNAL_PERSON_CANNOT_BE_DELETED',
            $this->responseString('code'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_child',
            'parents' => [
                ['personId' => $root['id'], 'role' => 'father'],
                ['personId' => $middle['id'], 'role' => 'father'],
            ],
            'relative' => ['personId' => $root['id']],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['parents_must_be_distinct'],
            $this->responseStringList('fields', 'parents'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'link_parent_child',
            'parentId' => $root['id'],
            'childId'  => $middle['id'],
            'role'     => 'father',
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'PARENT_LINK_ALREADY_EXISTS',
            $this->responseString('code'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $root['id'],
            'relative'         => ['personId' => $middle['id']],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['marriage_gender_pair_invalid'],
            $this->responseStringList('fields', 'personId'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_marriage_period',
            'personId' => $root['id'],
            'relative' => [
                'person' => $this->person(),
            ],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['marriage_gender_required'],
            $this->responseStringList('fields', 'personId'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type' => 'not_a_mutation',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['graph_mutation_type_invalid'],
            $this->responseStringList('fields', 'type'),
        );

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'unexpected' => true,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['graph_mutation_type_invalid'],
            $this->responseStringList('fields', 'type'),
        );
    }

    public function testCurrentSpouseWithCommonChildCannotBeDeleted(): void
    {
        [$accessToken, $tree, $snapshot, $male, $female] =
            $this->marriedTree();
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_child',
            'parents' => [
                ['personId' => $male['id'], 'role' => 'father'],
                ['personId' => $female['id'], 'role' => 'mother'],
            ],
            'relative' => [
                'person' => $this->person(),
            ],
        ]);

        $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'delete_person',
            'personId' => $male['id'],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'CURRENT_SPOUSE_COMMON_CHILD',
            $this->responseString('code'),
        );
    }

    /**
     * @return array{
     *     string,
     *     GraphTree,
     *     GraphSnapshot,
     *     GraphPerson,
     *     GraphPerson
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
                'gender'    => 'male',
                'birthDate' => $this->knownDate('year', 1960),
            ]),
        );
        $male = $snapshot['people'][0];
        $snapshot = $this->mutate($accessToken, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_marriage_period',
            'personId' => $male['id'],
            'relative' => [
                'person' => $this->person([
                    'gender'    => 'female',
                    'birthDate' => $this->knownDate('year', 1962),
                ]),
            ],
            'marriageDate'     => $this->knownDate('year', 1985),
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => 'Petrova',
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
     * @return GraphSnapshot
     */
    private function mutate(
        string $accessToken,
        string $treeId,
        string $etag,
        array $payload,
    ): array {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $treeId . '/graph-mutations',
            $payload,
            [
                ...$this->authorization($accessToken),
                'If-Match' => $etag,
            ],
        );

        /** @var GraphSnapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    /**
     * @return GraphSnapshot
     */
    private function snapshot(string $accessToken, string $treeId): array
    {
        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $treeId . '/snapshot',
            headers: $this->authorization($accessToken),
        );
        self::assertResponseIsSuccessful();

        /** @var GraphSnapshot $snapshot */
        $snapshot = $this->responseData();

        return $snapshot;
    }

    /**
     * @param GraphTree            $tree
     * @param array<string, mixed> $person
     *
     * @return GraphSnapshot
     */
    private function firstPerson(
        string $accessToken,
        array $tree,
        array $person,
    ): array {
        return $this->mutate($accessToken, $tree['id'], $tree['etag'], [
            'type'   => 'create_first_person',
            'person' => $person,
        ]);
    }

    /**
     * @param GraphSnapshot $snapshot
     *
     * @return GraphPerson
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
     * @return list<GraphEvent>
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

        $items = $this->responseData()['items'];
        self::assertIsArray($items);
        $events = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            /** @var GraphEvent $event */
            $event = $item;
            $events[] = $event;
        }

        return $events;
    }

    private function register(): string
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }

    /**
     * @return GraphTree
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

        /** @var GraphTree $tree */
        $tree = $this->responseData();

        return $tree;
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
            'givenName'    => ['state' => 'unknown'],
            'patronymic'   => ['state' => 'unknown'],
            'gender'       => 'unknown',
            'birthDate'    => ['state' => 'unknown'],
            'deathDate'    => null,
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
            'kind'  => $approximate ? 'approximate' : 'exact',
            'year'  => $year,
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
        return ['Authorization' => 'Bearer ' . $accessToken];
    }
}
