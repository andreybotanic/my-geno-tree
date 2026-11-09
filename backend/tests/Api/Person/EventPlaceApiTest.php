<?php

namespace App\Tests\Api\Person;

use App\Tests\Api\ApiWebTestCase;

/**
 * @phpstan-type Place array{name: string, coordinates: array{latitude: float, longitude: float}|null}
 * @phpstan-type PlaceEvent array{id: string, type: string, date: array<string, mixed>, place: Place|null}
 * @phpstan-type PersonRef array{id: string}
 * @phpstan-type ParentLinkRef array{parentId: string}
 * @phpstan-type PlaceSnapshot array{etag: string, people: non-empty-list<PersonRef>, parentLinks: list<ParentLinkRef>}
 * @phpstan-type PlaceMutation array{etag: string, event: PlaceEvent}
 */
final class EventPlaceApiTest extends ApiWebTestCase
{
    public function testAutomaticBirthPlaceIsSharedWithParentProjectionsAndCanBeDeleted(): void
    {
        $token = $this->register();
        [$treeId, $etag] = $this->createTree($token);
        $snapshot = $this->mutate($token, $treeId, $etag, [
            'type'   => 'create_first_person',
            'person' => [
                ...$this->person(),
                'birthPlace' => [
                    'name'        => 'London',
                    'coordinates' => ['latitude' => 51.5072, 'longitude' => -0.1276],
                ],
            ],
        ]);
        $child = $snapshot['people'][0];
        self::assertIsArray($child);
        self::assertIsString($child['id']);

        $snapshot = $this->mutate($token, $treeId, $snapshot['etag'], [
            'type'    => 'add_parents',
            'childId' => $child['id'],
        ]);
        $birth = $this->event($token, $treeId, $child['id'], 'birth');
        self::assertIsString($birth['id']);
        $placePayload = [
            'name'        => 'Novosibirsk',
            'coordinates' => ['latitude' => 55.0302, 'longitude' => 82.9204],
        ];

        $this->jsonRequest(
            'PUT',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $birth['id']),
            $placePayload,
            ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(412);

        $foreignToken = $this->register('place_outsider', 'place_outsider@example.test');
        $this->jsonRequest(
            'PUT',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $birth['id']),
            $placePayload,
            [
                'Authorization' => 'Bearer ' . $foreignToken,
                'If-Match'      => $snapshot['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest(
            'PUT',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $birth['id']),
            $placePayload,
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $snapshot['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var PlaceMutation $saved */
        $saved = $this->responseData();
        self::assertIsArray($saved['event']['place']);
        self::assertIsArray($saved['event']['place']['coordinates']);
        self::assertSame('Novosibirsk', $saved['event']['place']['name']);
        self::assertSame(55.0302, $saved['event']['place']['coordinates']['latitude']);
        self::assertIsString($saved['etag']);

        foreach ($snapshot['parentLinks'] as $link) {
            self::assertIsArray($link);
            self::assertIsString($link['parentId']);
            $projection = $this->event($token, $treeId, $link['parentId'], 'child_birth');
            self::assertIsArray($projection['place']);
            self::assertIsArray($projection['place']['coordinates']);
            self::assertSame('Novosibirsk', $projection['place']['name']);
            self::assertSame(82.9204, $projection['place']['coordinates']['longitude']);
        }

        $this->jsonRequest(
            'PUT',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $birth['id']),
            ['name' => 'Historical settlement', 'coordinates' => null],
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $saved['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var PlaceMutation $saved */
        $saved = $this->responseData();
        // JSON object member order is not part of the response contract.
        self::assertEquals(['name' => 'Historical settlement', 'coordinates' => null], $saved['event']['place']);
        foreach ($snapshot['parentLinks'] as $link) {
            $projection = $this->event($token, $treeId, $link['parentId'], 'child_birth');
            self::assertSame($saved['event']['place'], $projection['place']);
        }

        $this->jsonRequest(
            'DELETE',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $birth['id']),
            headers: [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $saved['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(204);
        $deleteEtag = $this->client->getResponse()->headers->get('ETag');
        self::assertIsString($deleteEtag);
        self::assertNull($this->event($token, $treeId, $child['id'], 'birth')['place']);

        $this->jsonRequest(
            'PUT',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $birth['id']),
            ['name' => 'Text without a point'],
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $deleteEtag,
            ],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('EVENT_PLACE_INVALID', $this->responseData()['code']);
        self::assertNull($this->event($token, $treeId, $child['id'], 'birth')['place']);
    }

    public function testResidenceAcceptsUnknownDateAndResolvedPlace(): void
    {
        $token = $this->register();
        [$treeId, $etag] = $this->createTree($token);
        $snapshot = $this->mutate($token, $treeId, $etag, [
            'type'   => 'create_first_person',
            'person' => $this->person(),
        ]);
        $person = $snapshot['people'][0];
        self::assertIsArray($person);

        $this->jsonRequest(
            'POST',
            sprintf('/api/v1/trees/%s/people/%s/events', $treeId, $person['id']),
            [
                'type'        => 'residence',
                'date'        => ['state' => 'unknown'],
                'description' => null,
                'place'       => [
                    'name'        => 'Tomsk',
                    'coordinates' => ['latitude' => 56.4846, 'longitude' => 84.9476],
                ],
            ],
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $snapshot['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(201);
        $response = $this->responseData();
        self::assertIsArray($response['event']);
        /** @var PlaceEvent $event */
        $event = $response['event'];
        self::assertIsArray($event['place']);
        self::assertSame('residence', $event['type']);
        self::assertSame(['state' => 'unknown'], $event['date']);
        self::assertSame('Tomsk', $event['place']['name']);

        self::assertIsString($response['etag']);
        $this->jsonRequest(
            'PUT',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $event['id']),
            [
                'name'        => 'Omsk',
                'coordinates' => ['latitude' => 54.9885, 'longitude' => 73.3242],
            ],
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $response['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        /** @var PlaceMutation $updated */
        $updated = $this->responseData();
        self::assertIsArray($updated['event']['place']);
        self::assertSame('Omsk', $updated['event']['place']['name']);

        $this->jsonRequest(
            'DELETE',
            sprintf('/api/v1/trees/%s/events/%s/place', $treeId, $event['id']),
            headers: [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $updated['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return PlaceSnapshot
     */
    private function mutate(string $token, string $treeId, string $etag, array $payload): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $treeId . '/graph-mutations',
            $payload,
            ['Authorization' => 'Bearer ' . $token, 'If-Match' => $etag],
        );
        self::assertResponseStatusCodeSame(201);

        /** @var PlaceSnapshot $response */
        $response = $this->responseData();

        return $response;
    }

    /** @return array<string, mixed> */
    private function event(string $token, string $treeId, string $personId, string $type): array
    {
        $this->jsonRequest(
            'GET',
            sprintf('/api/v1/trees/%s/people/%s/events', $treeId, $personId),
            headers: ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();
        $response = $this->responseData();
        self::assertIsArray($response['items']);
        foreach ($response['items'] as $event) {
            if (is_array($event) && ($event['type'] ?? null) === $type) {
                return $event;
            }
        }
        self::fail('Expected event was not found.');
    }

    /** @return array{string, string} */
    private function createTree(string $token): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees',
            ['name'          => 'Places'],
            ['Authorization' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(201);
        $treeId = $this->responseData()['id'];
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertIsString($treeId);
        self::assertIsString($etag);

        return [$treeId, $etag];
    }

    private function register(
        string $username = 'family_admin',
        string $email = 'family@example.test',
    ): string {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration([
            'username' => $username,
            'email'    => $email,
        ]));
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('accessToken');
    }

    /** @return array<string, mixed> */
    private function person(): array
    {
        return [
            'birthSurname' => ['state' => 'unknown'],
            'givenName'    => ['state' => 'unknown'],
            'patronymic'   => ['state' => 'unknown'],
            'gender'       => 'unknown',
            'birthDate'    => ['state' => 'unknown'],
            'deathDate'    => null,
        ];
    }
}
