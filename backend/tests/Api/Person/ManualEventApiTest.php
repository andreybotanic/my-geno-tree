<?php

namespace App\Tests\Api\Person;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @phpstan-type ManualTree array{id: string, etag: string}
 * @phpstan-type ManualPerson array{id: string}
 * @phpstan-type ManualEvent array{
 *     id: string,
 *     type: string,
 *     source: string,
 *     title: string|null,
 *     description: string|null,
 *     data: array<string, mixed>,
 *     sourceEntityId: string|null,
 *     manualOrder: int|null
 * }
 * @phpstan-type ManualSnapshot array{etag: string, people: non-empty-list<ManualPerson>}
 * @phpstan-type EventMutation array{etag: string, event: ManualEvent}
 */
final class ManualEventApiTest extends ApiWebTestCase
{
    public function testManualEventCrudAndAutomaticEventsAreImmutable(): void
    {
        [$accessToken, $tree, $snapshot, $person] = $this->personTree();
        $created = $this->createEvent(
            $accessToken,
            $tree['id'],
            $person['id'],
            $snapshot['etag'],
            [
                'type'        => 'custom',
                'date'        => $this->knownDate('year', 1950),
                'title'       => '  Graduation  ',
                'description' => "<script>alert(1)</script>\nPlain text",
            ],
        );

        self::assertResponseStatusCodeSame(201);
        self::assertInstanceOf(
            UuidV7::class,
            Uuid::fromString($created['event']['id']),
        );
        self::assertSame('manual', $created['event']['source']);
        self::assertSame('Graduation', $created['event']['title']);
        self::assertSame(
            "<script>alert(1)</script>\nPlain text",
            $created['event']['description'],
        );
        self::assertSame([], $created['event']['data']);
        self::assertNull($created['event']['sourceEntityId']);
        self::assertNotSame($snapshot['etag'], $created['etag']);

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s/events/%s',
                $tree['id'],
                $person['id'],
                $created['event']['id'],
            ),
            [
                'type'       => 'surname_change',
                'date'       => $this->knownDate('month', 1960, month: 2),
                'newSurname' => '  Petrova  ',
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $created['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var EventMutation $updated */
        $updated = $this->responseData();
        self::assertSame('surname_change', $updated['event']['type']);
        self::assertNull($updated['event']['title']);
        self::assertNull($updated['event']['description']);
        self::assertSame(
            ['newSurname' => 'Petrova'],
            $updated['event']['data'],
        );

        $automatic = $this->events(
            $accessToken,
            $tree['id'],
            $person['id'],
        )[0];
        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s/events/%s',
                $tree['id'],
                $person['id'],
                $automatic['id'],
            ),
            [
                'type'        => 'custom',
                'date'        => ['state' => 'unknown'],
                'title'       => 'Blocked',
                'description' => null,
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $updated['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest(
            'DELETE',
            sprintf(
                '/api/v1/trees/%s/people/%s/events/%s',
                $tree['id'],
                $person['id'],
                $created['event']['id'],
            ),
            headers: [
                ...$this->authorization($accessToken),
                'If-Match' => $updated['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(204);
        self::assertNotSame(
            $updated['etag'],
            $this->client->getResponse()->headers->get('ETag'),
        );
        self::assertSame(
            ['birth', 'death'],
            array_column(
                $this->events(
                    $accessToken,
                    $tree['id'],
                    $person['id'],
                ),
                'type',
            ),
        );
    }

    public function testManualEventValidationAndOwnershipAreAtomic(): void
    {
        [$accessToken, $tree, $snapshot, $person] = $this->personTree();
        $path = sprintf(
            '/api/v1/trees/%s/people/%s/events',
            $tree['id'],
            $person['id'],
        );
        $valid = [
            'type'        => 'custom',
            'date'        => ['state' => 'unknown'],
            'title'       => 'Memory',
            'description' => null,
        ];

        $this->jsonRequest(
            'POST',
            $path,
            $valid,
            $this->authorization($accessToken),
        );
        self::assertResponseStatusCodeSame(412);
        self::assertCount(
            2,
            $this->events($accessToken, $tree['id'], $person['id']),
        );

        foreach ([
            [
                ...$valid,
                'date' => $this->knownDate('year', 1800),
            ],
            [
                ...$valid,
                'date' => $this->knownDate('year', 2001),
            ],
            [
                ...$valid,
                'title' => str_repeat('x', 201),
            ],
            [
                ...$valid,
                'description' => str_repeat('x', 5001),
            ],
            [
                'type'       => 'surname_change',
                'date'       => ['state' => 'unknown'],
                'newSurname' => ' ',
            ],
            [
                ...$valid,
                'futureField' => true,
            ],
        ] as $invalid) {
            $this->jsonRequest(
                'POST',
                $path,
                $invalid,
                [
                    ...$this->authorization($accessToken),
                    'If-Match' => $snapshot['etag'],
                ],
            );
            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                'EVENT_DATA_INVALID',
                $this->responseString('code'),
            );
        }
        self::assertCount(
            2,
            $this->events($accessToken, $tree['id'], $person['id']),
        );

        $otherToken = $this->register([
            'username' => 'other_user',
            'email'    => 'other@example.test',
        ]);
        $this->jsonRequest(
            'POST',
            $path,
            $valid,
            [
                ...$this->authorization($otherToken),
                'If-Match' => $snapshot['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAmbiguousEventsCanBeManuallyOrdered(): void
    {
        [$accessToken, $tree, $snapshot, $person] = $this->personTree();
        $first = $this->createEvent(
            $accessToken,
            $tree['id'],
            $person['id'],
            $snapshot['etag'],
            [
                'type'        => 'custom',
                'date'        => $this->knownDate('year', 1900),
                'title'       => 'First memory',
                'description' => null,
            ],
        );
        $second = $this->createEvent(
            $accessToken,
            $tree['id'],
            $person['id'],
            $first['etag'],
            [
                'type'       => 'surname_change',
                'date'       => $this->knownDate('year', 1900),
                'newSurname' => 'Petrov',
            ],
        );
        $birth = array_values(array_filter(
            $this->events($accessToken, $tree['id'], $person['id']),
            static fn (array $event): bool => 'birth' === $event['type'],
        ))[0];
        $orderedIds = [
            $second['event']['id'],
            $birth['id'],
            $first['event']['id'],
        ];

        $this->jsonRequest(
            'PUT',
            sprintf(
                '/api/v1/trees/%s/people/%s/event-order',
                $tree['id'],
                $person['id'],
            ),
            ['eventIds' => $orderedIds],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $second['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var array{etag: string, items: list<ManualEvent>} $reordered */
        $reordered = $this->responseData();
        self::assertSame(
            $orderedIds,
            array_slice(array_column($reordered['items'], 'id'), 0, 3),
        );
        self::assertSame(
            [0, 1, 2],
            array_slice(array_column($reordered['items'], 'manualOrder'), 0, 3),
        );

        $later = $this->createEvent(
            $accessToken,
            $tree['id'],
            $person['id'],
            $reordered['etag'],
            [
                'type'        => 'custom',
                'date'        => $this->knownDate('year', 1950),
                'title'       => 'Later',
                'description' => null,
            ],
        );
        $orderPath = sprintf(
            '/api/v1/trees/%s/people/%s/event-order',
            $tree['id'],
            $person['id'],
        );
        $this->jsonRequest(
            'PUT',
            $orderPath,
            ['eventIds' => [$birth['id'], $later['event']['id']]],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $later['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('EVENT_ORDER_INVALID', $this->responseString('code'));

        foreach ([
            ['eventIds' => [$birth['id'], $birth['id']]],
            ['eventIds' => ['not-a-uuid', $birth['id']]],
        ] as $invalid) {
            $this->jsonRequest(
                'PUT',
                $orderPath,
                $invalid,
                [
                    ...$this->authorization($accessToken),
                    'If-Match' => $later['etag'],
                ],
            );
            self::assertResponseStatusCodeSame(422);
        }
        $this->jsonRequest(
            'PUT',
            $orderPath,
            ['eventIds' => [$birth['id'], Uuid::v7()->toRfc4122()]],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $later['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest(
            'PATCH',
            sprintf(
                '/api/v1/trees/%s/people/%s/events/%s',
                $tree['id'],
                $person['id'],
                $first['event']['id'],
            ),
            [
                'type'        => 'custom',
                'date'        => $this->knownDate('year', 1940),
                'title'       => 'Moved memory',
                'description' => null,
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $later['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var EventMutation $updated */
        $updated = $this->responseData();
        self::assertNull($updated['event']['manualOrder']);
    }

    /**
     * @return array{
     *     string,
     *     ManualTree,
     *     ManualSnapshot,
     *     ManualPerson
     * }
     */
    private function personTree(): array
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken);
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            [
                'type'   => 'create_first_person',
                'person' => [
                    'birthSurname' => ['state' => 'unknown'],
                    'givenName'    => ['state' => 'unknown'],
                    'patronymic'   => ['state' => 'unknown'],
                    'gender'       => 'unknown',
                    'birthDate'    => $this->knownDate('year', 1900),
                    'deathDate'    => $this->knownDate('year', 2000),
                ],
            ],
            [
                ...$this->authorization($accessToken),
                'If-Match' => $tree['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(201);
        /** @var ManualSnapshot $snapshot */
        $snapshot = $this->responseData();

        return [$accessToken, $tree, $snapshot, $snapshot['people'][0]];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return EventMutation
     */
    private function createEvent(
        string $accessToken,
        string $treeId,
        string $personId,
        string $etag,
        array $payload,
    ): array {
        $this->jsonRequest(
            'POST',
            sprintf(
                '/api/v1/trees/%s/people/%s/events',
                $treeId,
                $personId,
            ),
            $payload,
            [
                ...$this->authorization($accessToken),
                'If-Match' => $etag,
            ],
        );
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [201],
        );

        /** @var EventMutation $response */
        $response = $this->responseData();

        return $response;
    }

    /**
     * @return list<ManualEvent>
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
            /** @var ManualEvent $event */
            $event = $item;
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function register(array $overrides = []): string
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            array_replace($this->validRegistration(), $overrides),
        );
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }

    /**
     * @return ManualTree
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
        /** @var array{id: string} $tree */
        $tree = $this->responseData();
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);

        return [...$tree, 'etag' => $etag];
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function knownDate(
        string $precision,
        int $year,
        ?int $month = null,
    ): array {
        $date = [
            'state'       => 'known',
            'precision'   => $precision,
            'year'        => $year,
            'approximate' => false,
        ];
        if (null !== $month) {
            $date['month'] = $month;
        }

        return $date;
    }

    /**
     * @return array<string, string>
     */
    private function authorization(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }
}
