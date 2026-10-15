<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class TreeApiTest extends ApiWebTestCase
{
    public function testStatisticsArePublicAndTrackAccountsAndTrees(): void
    {
        $this->jsonRequest('GET', '/api/v1/statistics');
        self::assertResponseIsSuccessful();
        self::assertSame([
            'accountCount' => 0,
            'treeCount'    => 0,
            'personCount'  => 0,
        ], $this->responseData());

        $accessToken = $this->register();
        $this->createTree($accessToken, 'Family history');

        $this->jsonRequest('GET', '/api/v1/statistics');
        self::assertResponseIsSuccessful();
        self::assertSame([
            'accountCount' => 1,
            'treeCount'    => 1,
            'personCount'  => 0,
        ], $this->responseData());
    }

    public function testStatisticsCountPeopleAcrossAllOwnersAndTrackDeletions(): void
    {
        $firstToken = $this->register();
        $this->createTree($firstToken, 'Empty tree');
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration([
            'username' => 'second_owner',
            'email'    => 'second@example.test',
        ]));
        self::assertResponseStatusCodeSame(201);
        $secondToken = $this->responseString('accessToken');

        $populatedTrees = [];
        foreach ([$firstToken, $firstToken, $secondToken] as $index => $token) {
            $tree = $this->createTree($token, 'Family ' . $index);
            $this->jsonRequest('POST', '/api/v1/trees/' . $tree['id'] . '/graph-mutations', [
                'type'   => 'create_first_person',
                'person' => [
                    'birthSurname' => ['state' => 'unknown'],
                    'givenName'    => ['state' => 'unknown'],
                    'patronymic'   => ['state' => 'unknown'],
                    'gender'       => 'unknown',
                    'birthDate'    => ['state' => 'unknown'],
                    'deathDate'    => null,
                ],
            ], [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $tree['etag'],
            ]);
            self::assertResponseStatusCodeSame(201);
            /** @var array{etag: string, people: list<array{id: string}>} $snapshot */
            $snapshot = $this->responseData();
            $populatedTrees[] = [
                'id'       => $tree['id'],
                'etag'     => $snapshot['etag'],
                'personId' => $snapshot['people'][0]['id'],
            ];
        }

        foreach ([[], ['Authorization' => 'Bearer ' . $firstToken]] as $headers) {
            $this->jsonRequest('GET', '/api/v1/statistics', headers: $headers);
            self::assertResponseIsSuccessful();
            self::assertSame([
                'accountCount' => 2,
                'treeCount'    => 4,
                'personCount'  => 3,
            ], $this->responseData());
        }

        $this->jsonRequest('POST', '/api/v1/trees/' . $populatedTrees[0]['id'] . '/graph-mutations', [
            'type'     => 'delete_person',
            'personId' => $populatedTrees[0]['personId'],
        ], [
            'Authorization' => 'Bearer ' . $firstToken,
            'If-Match'      => $populatedTrees[0]['etag'],
        ]);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/v1/statistics');
        self::assertSame(2, $this->responseData()['personCount']);

        $this->jsonRequest('DELETE', '/api/v1/trees/' . $populatedTrees[2]['id'], headers: [
            'Authorization' => 'Bearer ' . $secondToken,
            'If-Match'      => $populatedTrees[2]['etag'],
        ]);
        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest('GET', '/api/v1/statistics');
        self::assertSame([
            'accountCount' => 2,
            'treeCount'    => 3,
            'personCount'  => 1,
        ], $this->responseData());
    }

    public function testOwnerCanCreateAndListTreesNewestFirst(): void
    {
        $accessToken = $this->register();
        $first = $this->createTree($accessToken, '  First family  ');

        self::assertSame('First family', $first['name']);
        self::assertSame(0, $first['personCount']);
        self::assertInstanceOf(
            UuidV7::class,
            Uuid::fromString($first['id']),
        );
        self::assertSame($first['etag'], $this->client
            ->getResponse()
            ->headers
            ->get('ETag'));
        $second = $this->createTree($accessToken, 'Second family');

        $this->jsonRequest('GET', '/api/v1/trees', headers: [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseIsSuccessful();
        /** @var list<array{id: string}> $items */
        $items = $this->responseData()['items'];
        self::assertCount(2, $items);
        self::assertSame($second['id'], $items[0]['id']);
        self::assertSame($first['id'], $items[1]['id']);
    }

    public function testTreeNameValidationAndCaseSensitiveUniqueness(): void
    {
        $accessToken = $this->register();
        $this->createTree($accessToken, 'Family');

        $this->jsonRequest('POST', '/api/v1/trees', [
            'name' => 'Family',
        ], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            ['tree_name_not_unique'],
            $this->responseStringList('fields', 'name'),
        );

        $this->createTree($accessToken, 'family');
        self::assertResponseStatusCodeSame(201);

        foreach (['   ', str_repeat('x', 101)] as $invalidName) {
            $this->jsonRequest('POST', '/api/v1/trees', [
                'name' => $invalidName,
            ], [
                'Authorization' => 'Bearer ' . $accessToken,
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                ['tree_name_invalid'],
                $this->responseStringList('fields', 'name'),
            );
        }
    }

    public function testRenameRequiresTheCurrentEtag(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'Original');

        $this->renameTree($accessToken, $tree['id'], 'Changed', null);
        self::assertResponseStatusCodeSame(412);

        $this->renameTree(
            $accessToken,
            $tree['id'],
            'Changed',
            $tree['etag'],
        );
        self::assertResponseIsSuccessful();
        /** @var array{id: string, name: string, etag: string, personCount: int} $renamed */
        $renamed = $this->responseData();
        self::assertSame('Changed', $renamed['name']);
        self::assertNotSame($tree['etag'], $renamed['etag']);
        self::assertSame(
            $renamed['etag'],
            $this->client->getResponse()->headers->get('ETag'),
        );

        $this->renameTree(
            $accessToken,
            $tree['id'],
            'Changed again',
            $tree['etag'],
        );
        self::assertResponseStatusCodeSame(412);
        self::assertSame(
            'TREE_VERSION_CONFLICT',
            $this->responseString('code'),
        );
    }

    public function testRenamePreservesOwnerScopedNameUniqueness(): void
    {
        $accessToken = $this->register();
        $this->createTree($accessToken, 'Existing');
        $tree = $this->createTree($accessToken, 'Other');

        $this->renameTree(
            $accessToken,
            $tree['id'],
            'Existing',
            $tree['etag'],
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            ['tree_name_not_unique'],
            $this->responseStringList('fields', 'name'),
        );
    }

    public function testDeleteRequiresConfirmationVersionAndUpdatesStatistics(): void
    {
        $accessToken = $this->register();
        $tree = $this->createTree($accessToken, 'Disposable');

        $this->jsonRequest(
            'DELETE',
            '/api/v1/trees/' . $tree['id'],
            headers: [
                'Authorization' => 'Bearer ' . $accessToken,
                'If-Match'      => $tree['etag'],
            ],
        );
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/v1/trees', headers: [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        self::assertSame([], $this->responseData()['items']);

        $this->jsonRequest('GET', '/api/v1/statistics');
        self::assertSame(0, $this->responseData()['treeCount']);
    }

    public function testForeignAndMissingTreesReturnTheSameNotFoundError(): void
    {
        $firstAccessToken = $this->register();
        $tree = $this->createTree($firstAccessToken, 'Private tree');

        $this->jsonRequest('POST', '/api/v1/auth/register', [
            'username' => 'second_owner',
            'email'    => 'second@example.test',
            'password' => 'StrongPass2!',
            'language' => 'en',
        ]);
        $secondAccessToken = $this->responseString('accessToken');

        $this->renameTree(
            $secondAccessToken,
            $tree['id'],
            'Intrusion',
            $tree['etag'],
        );
        self::assertResponseStatusCodeSame(404);
        $foreignResponse = $this->client->getResponse()->getContent();

        $this->renameTree(
            $secondAccessToken,
            Uuid::v7()->toRfc4122(),
            'Missing',
            $tree['etag'],
        );
        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            $foreignResponse,
            $this->client->getResponse()->getContent(),
        );

        $this->jsonRequest('GET', '/api/v1/trees', headers: [
            'Authorization' => 'Bearer ' . $secondAccessToken,
        ]);
        self::assertSame([], $this->responseData()['items']);
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
     * @return array{id: string, name: string, etag: string, personCount: int}
     */
    private function createTree(string $accessToken, string $name): array
    {
        $this->jsonRequest('POST', '/api/v1/trees', [
            'name' => $name,
        ], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string, name: string, etag: string, personCount: int} $tree */
        $tree = $this->responseData();

        return $tree;
    }

    private function renameTree(
        string $accessToken,
        string $treeId,
        string $name,
        ?string $etag,
    ): void {
        $headers = ['Authorization' => 'Bearer ' . $accessToken];
        if (null !== $etag) {
            $headers['If-Match'] = $etag;
        }
        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $treeId,
            ['name' => $name],
            $headers,
        );
    }
}
