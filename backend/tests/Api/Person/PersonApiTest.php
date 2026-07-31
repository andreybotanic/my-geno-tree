<?php

namespace App\Tests\Api\Person;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class PersonApiTest extends ApiWebTestCase
{
    public function testFirstPersonCreatesAnAtomicSnapshotAndLifecycleEvents(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'History');
        $snapshot = $this->createFirstPerson(
            $accessToken,
            $tree,
            $this->person([
                'birthSurname' => [
                    'state' => 'known',
                    'value' => '  Иванов  ',
                ],
                'givenName' => ['state' => 'unknown'],
                'birthDate' => $this->knownDate(
                    'month',
                    1994,
                    month: 10,
                ),
                'deathDate' => ['state' => 'unknown'],
            ]),
            ['x' => 1200.5, 'y' => -340],
        );

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($tree['etag'], $snapshot['etag']);
        self::assertSame(
            $snapshot['etag'],
            $this->client->getResponse()->headers->get('ETag'),
        );
        self::assertCount(1, $snapshot['people']);
        self::assertSame([], $snapshot['parentLinks']);
        self::assertSame([], $snapshot['marriages']);
        $person = $snapshot['people'][0];
        self::assertInstanceOf(
            UuidV7::class,
            Uuid::fromString($person['id']),
        );
        self::assertSame([
            'state' => 'known',
            'value' => 'Иванов',
        ], $person['birthSurname']);
        self::assertSame(['state' => 'unknown'], $person['givenName']);
        self::assertSame('unknown', $person['gender']);
        self::assertSame(
            $this->knownDate('month', 1994, month: 10),
            $person['birthDate'],
        );
        self::assertSame(['state' => 'unknown'], $person['deathDate']);
        self::assertSame(
            ['x' => 1200.5, 'y' => -340],
            $person['coordinates'],
        );

        $this->jsonRequest(
            'GET',
            sprintf(
                '/api/v1/trees/%s/people/%s',
                $tree['id'],
                $person['id'],
            ),
            headers: $this->authorization($accessToken),
        );
        self::assertResponseIsSuccessful();
        self::assertSame($person, $this->responseData());

        $events = $this->events(
            $accessToken,
            $tree['id'],
            $person['id'],
        );
        self::assertSame(['birth', 'death'], array_column(
            $events,
            'type',
        ));
        self::assertSame(
            ['automatic', 'automatic'],
            array_column($events, 'source'),
        );
        self::assertSame(
            ['person', 'person'],
            array_column($events, 'sourceEntityType'),
        );
        self::assertSame(
            [$person['id'], $person['id']],
            array_column($events, 'sourceEntityId'),
        );

        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $tree['id'] . '/snapshot',
            headers: $this->authorization($accessToken),
        );
        self::assertResponseIsSuccessful();
        self::assertSame($snapshot, $this->responseData());

        $this->jsonRequest(
            'GET',
            '/api/v1/trees',
            headers: $this->authorization($accessToken),
        );
        self::assertSame(1, $this->responseData()['items'][0]['personCount']);
    }

    public function testPersonFieldsAutosaveAndSynchronizeAutomaticEvents(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'History');
        $snapshot = $this->createFirstPerson(
            $accessToken,
            $tree,
            $this->person(),
        );
        $person = $snapshot['people'][0];

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s',
                $tree['id'],
                $person['id'],
            ),
            [
                'givenName' => [
                    'state' => 'known',
                    'value' => 'Анна',
                ],
                'gender'    => 'female',
                'birthDate' => $this->knownDate('year', 1900, true),
                'deathDate' => null,
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $snapshot['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        $result = $this->responseData();
        $updated = $result['person'];
        $updatedEtag = $this->client->getResponse()->headers->get('ETag');
        self::assertNotSame($snapshot['etag'], $updatedEtag);
        self::assertSame($updatedEtag, $result['etag']);
        self::assertSame('female', $updated['gender']);
        self::assertSame(null, $updated['deathDate']);
        self::assertSame(
            $this->knownDate('year', 1900, true),
            $updated['birthDate'],
        );

        $events = $this->events(
            $accessToken,
            $tree['id'],
            $person['id'],
        );
        self::assertSame(['birth'], array_column($events, 'type'));
        self::assertSame(
            $this->knownDate('year', 1900, true),
            $events[0]['date'],
        );

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s',
                $tree['id'],
                $person['id'],
            ),
            ['gender' => 'male'],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $snapshot['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(412);
        self::assertSame(
            'TREE_VERSION_CONFLICT',
            $this->responseData()['code'],
        );
    }

    public function testPersonWireTypesAndChronologyAreValidated(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'History');
        $invalidPeople = [
            $this->person([
                'birthSurname' => [
                    'state' => 'unknown',
                    'value' => 'not allowed',
                ],
            ]),
            $this->person([
                'birthDate' => $this->knownDate(
                    'month',
                    1900,
                    true,
                    2,
                ),
            ]),
            $this->person([
                'birthDate' => [
                    'state'       => 'known',
                    'precision'   => 'day',
                    'year'        => 1900,
                    'month'       => 2,
                    'day'         => 30,
                    'approximate' => false,
                ],
            ]),
            $this->person([
                'birthDate' => $this->knownDate('year', 1581),
            ]),
            $this->person([
                'birthDate' => $this->knownDate('year', 2000),
                'deathDate' => $this->knownDate('year', 1999),
            ]),
        ];

        foreach ($invalidPeople as $person) {
            $this->createFirstPerson($accessToken, $tree, $person);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                'PERSON_DATA_INVALID',
                $this->responseData()['code'],
            );
        }

        $this->createFirstPerson(
            $accessToken,
            $tree,
            $this->person(),
            ['x' => 'left', 'y' => 0],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['finite_number_required'],
            $this->responseData()['fields']['coordinates.x'],
        );
    }

    public function testGraphMutationRequiresAJsonObject(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'History');
        $server = [
            'HTTPS'              => 'on',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
            'HTTP_IF_MATCH'      => $tree['etag'],
        ];

        $this->client->request(
            'POST',
            'https://localhost/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            server: [...$server, 'CONTENT_TYPE' => 'application/json'],
            content: '{',
        );
        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            'INVALID_REQUEST_BODY',
            $this->responseData()['code'],
        );

        $this->client->request(
            'POST',
            'https://localhost/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            server: [...$server, 'CONTENT_TYPE' => 'application/json'],
            content: '[]',
        );
        self::assertResponseStatusCodeSame(400);

        $this->client->request(
            'POST',
            'https://localhost/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            server: [...$server, 'CONTENT_TYPE' => 'text/plain'],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(415);
    }

    public function testOnlyOneFirstPersonCanBeCreated(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'History');
        $snapshot = $this->createFirstPerson(
            $accessToken,
            $tree,
            $this->person(),
        );

        $this->createFirstPerson(
            $accessToken,
            ['id' => $tree['id'], 'etag' => $snapshot['etag']],
            $this->person(),
        );
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'FIRST_PERSON_ALREADY_EXISTS',
            $this->responseData()['code'],
        );
    }

    public function testForeignAndMismatchedPersonResourcesStayPrivate(): void
    {
        $firstToken = $this->register();
        $firstTree = $this->createTree($firstToken, 'First');
        $snapshot = $this->createFirstPerson(
            $firstToken,
            $firstTree,
            $this->person(),
        );
        $personId = $snapshot['people'][0]['id'];
        $secondTree = $this->createTree($firstToken, 'Second');

        $this->jsonRequest(
            'GET',
            sprintf(
                '/api/v1/trees/%s/people/%s',
                $secondTree['id'],
                $personId,
            ),
            headers: $this->authorization($firstToken),
        );
        self::assertResponseStatusCodeSame(404);
        $mismatched = $this->client->getResponse()->getContent();

        $secondToken = $this->register([
            'username' => 'second_owner',
            'email'    => 'second@example.test',
        ]);
        $this->jsonRequest(
            'GET',
            '/api/v1/trees/' . $firstTree['id'] . '/snapshot',
            headers: $this->authorization($secondToken),
        );
        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            $mismatched,
            $this->client->getResponse()->getContent(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function register(array $overrides = []): string
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration($overrides),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->responseData()['accessToken'];
    }

    /**
     * @return array<string, mixed>
     */
    private function createTree(string $accessToken, string $name): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees',
            ['name' => $name],
            $this->authorization($accessToken),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->responseData();
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $person
     * @param array<string, mixed> $coordinates
     *
     * @return array<string, mixed>
     */
    private function createFirstPerson(
        string $accessToken,
        array $tree,
        array $person,
        array $coordinates = ['x' => 0, 'y' => 0],
    ): array {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            [
                'type'        => 'create_first_person',
                'person'      => $person,
                'coordinates' => $coordinates,
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $tree['etag'],
            ],
        );

        return $this->responseData();
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
            'state'       => 'known',
            'precision'   => $precision,
            'year'        => $year,
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
        return ['Authorization' => 'Bearer ' . $accessToken];
    }
}
