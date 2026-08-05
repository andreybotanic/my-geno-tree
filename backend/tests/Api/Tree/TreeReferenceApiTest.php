<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;

/**
 * @phpstan-type ReferenceSnapshot array{
 *     etag: string,
 *     people: list<array{id: string}>,
 *     parentLinks: list<array{parentId: string}>,
 *     marriages: list<array{id: string}>,
 *     tree: array{reference: array{type: string, personId: string, personIds: list<string>}}
 * }
 */
final class TreeReferenceApiTest extends ApiWebTestCase
{
    public function testPersonAndExistingMarriageCanBeSelectedWithCanonicalIds(): void
    {
        [$token, $tree, $snapshot, $first, $second] = $this->marriedTree();

        $snapshot = $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'      => 'marriage',
            'personIds' => [$second, $first],
        ]);
        self::assertResponseIsSuccessful();
        $ids = [$first, $second];
        sort($ids);
        self::assertSame(
            ['type' => 'marriage', 'personIds' => $ids],
            $snapshot['tree']['reference'],
        );
        self::assertSame(
            $snapshot['etag'],
            $this->client->getResponse()->headers->get('ETag'),
        );

        $snapshot = $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'person',
            'personId' => $second,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['type' => 'person', 'personId' => $second],
            $snapshot['tree']['reference'],
        );
    }

    public function testInvalidMarriageForeignPersonUuidAndStaleEtagAreRejected(): void
    {
        [$token, $tree, $snapshot, $first] = $this->marriedTree();
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'add_parent',
            'childId'  => $first,
            'role'     => 'father',
            'relative' => ['person' => $this->person('male')],
        ]);
        $unmarried = $snapshot['parentLinks'][0]['parentId'];

        $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'      => 'marriage',
            'personIds' => [$first, $unmarried],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['marriage_required'],
            $this->responseStringList('fields', 'personIds'),
        );

        $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'person',
            'personId' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['uuid_required'], $this->responseStringList('fields', 'personId'));

        $this->change($token, $tree['id'], '"stale"', [
            'type'     => 'person',
            'personId' => $first,
        ]);
        self::assertResponseStatusCodeSame(412);

        $foreignTree = $this->createTree($token, 'Foreign');
        $foreign = $this->mutate($token, $foreignTree['id'], $foreignTree['etag'], [
            'type'   => 'create_first_person',
            'person' => $this->person(),
        ]);
        $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'person',
            'personId' => $foreign['people'][0]['id'],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testReferenceMarriageSurvivesOnePeriodAndFallsBackAfterTheLast(): void
    {
        [$token, $tree, $snapshot, $first, $second] = $this->marriedTree();
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'    => 'add_child',
            'parents' => [
                ['personId' => $first, 'role' => 'father'],
                ['personId' => $second, 'role' => 'mother'],
            ],
            'relative' => ['person' => $this->person()],
        ]);
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $first,
            'relative'         => ['personId' => $second],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => ['state' => 'unknown'],
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $snapshot = $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'      => 'marriage',
            'personIds' => [$first, $second],
        ]);

        $firstPeriod = $snapshot['marriages'][0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'       => 'remove_marriage_period',
            'marriageId' => $firstPeriod,
        ]);
        self::assertSame('marriage', $snapshot['tree']['reference']['type']);

        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'       => 'remove_marriage_period',
            'marriageId' => $snapshot['marriages'][0]['id'],
        ]);
        self::assertSame('person', $snapshot['tree']['reference']['type']);
        self::assertContains($snapshot['tree']['reference']['personId'], [$first, $second]);
    }

    public function testDeletingOneReferenceSpouseKeepsTheSurvivor(): void
    {
        [$token, $tree, $snapshot, $first, $second] = $this->marriedTree();
        $snapshot = $this->change($token, $tree['id'], $snapshot['etag'], [
            'type'      => 'marriage',
            'personIds' => [$first, $second],
        ]);
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'     => 'delete_person',
            'personId' => $second,
        ]);

        self::assertSame(
            ['type' => 'person', 'personId' => $first],
            $snapshot['tree']['reference'],
        );
    }

    /** @return array{string, array{id: string, etag: string}, ReferenceSnapshot, string, string} */
    private function marriedTree(): array
    {
        $token = $this->register();
        $tree = $this->createTree($token, 'Family');
        $snapshot = $this->mutate($token, $tree['id'], $tree['etag'], [
            'type'   => 'create_first_person',
            'person' => $this->person('male'),
        ]);
        $first = $snapshot['people'][0]['id'];
        $snapshot = $this->mutate($token, $tree['id'], $snapshot['etag'], [
            'type'             => 'add_marriage_period',
            'personId'         => $first,
            'relative'         => ['person' => $this->person('female')],
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ]);
        $second = array_values(array_filter(
            $snapshot['people'],
            static fn (array $person): bool => $person['id'] !== $first,
        ))[0]['id'];

        return [$token, $tree, $snapshot, $first, $second];
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
     * @return ReferenceSnapshot
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

        /** @var ReferenceSnapshot $response */
        $response = $this->responseData();

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return ReferenceSnapshot
     */
    private function change(string $token, string $treeId, string $etag, array $payload): array
    {
        $this->jsonRequest(
            'PUT',
            '/api/v1/trees/' . $treeId . '/reference',
            $payload,
            [...$this->auth($token), 'If-Match' => $etag],
        );

        /** @var ReferenceSnapshot $response */
        $response = $this->responseData();

        return $response;
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
