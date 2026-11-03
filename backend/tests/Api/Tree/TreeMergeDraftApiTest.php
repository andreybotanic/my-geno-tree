<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use Symfony\Component\Uid\Uuid;

final class TreeMergeDraftApiTest extends ApiWebTestCase
{
    /** @var array<string, string> */
    private array $headers;
    private string $treeId;
    private string $etag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Merge family'], $this->headers);
        $this->treeId = $this->responseString('id');
        $this->etag = $this->responseString('etag');
    }

    public function testPersistsConversationWithoutChangingTreeAndDeduplicatesRetries(): void
    {
        $path = $this->createDraft();
        $message = ['version' => 1, 'requestId' => 'first-message', 'content' => 'Мама Ирины — Вера.'];
        $this->jsonRequest('POST', $path . '/messages', $message, $this->headers);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->responseData()['version']);
        // A saved message is classified before it can change the prepared package.
        self::assertSame(0, $this->responseData()['sourceVersion']);
        $saved = $this->responseData();
        $this->jsonRequest('POST', $path . '/messages', $message, $this->headers);
        self::assertResponseIsSuccessful();
        self::assertSame($saved, $this->responseData());
        $this->jsonRequest('GET', $path, headers: $this->headers);
        self::assertSame($saved, $this->responseData());
        $this->jsonRequest('GET', $this->base(), headers: $this->headers);
        self::assertSame([$saved], $this->responseData()['items']);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame($this->etag, $this->responseData()['etag']);
        self::assertSame([], $this->responseData()['people']);

        $this->jsonRequest('POST', $path . '/messages', array_replace($message, ['content' => 'Different']), $this->headers);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TREE_MERGE_REQUEST_CONFLICT', $this->responseString('code'));
        $this->jsonRequest('POST', $path . '/messages', array_replace($message, ['requestId' => 'new']), $this->headers);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TREE_MERGE_VERSION_CONFLICT', $this->responseString('code'));
        $this->jsonRequest('POST', $path . '/cancel', ['version' => 2], $this->headers);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->responseString('state'));
        $this->jsonRequest('POST', $path . '/cancel', ['version' => 2], $this->headers);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', $path . '/messages', array_replace($message, ['version' => 3, 'requestId' => 'new']), $this->headers);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TREE_MERGE_CLOSED', $this->responseString('code'));
    }

    public function testValidatesShapeLimitsAndReference(): void
    {
        foreach ([['unexpected' => true], ['referencePersonId' => 12], ['referencePersonId' => Uuid::v7()->toRfc4122()]] as $payload) {
            $this->jsonRequest('POST', $this->base(), $payload, $this->headers);
            self::assertResponseStatusCodeSame(422);
        }
        $path = $this->createDraft();
        foreach ([
            ['unexpected' => true],
            ['version' => '1', 'requestId' => 'a', 'content' => 'Family'],
            ['version' => 1, 'requestId' => '', 'content' => 'Family'],
            ['version' => 1, 'requestId' => 'a', 'content' => '  '],
            ['version' => 1, 'requestId' => 'a', 'content' => str_repeat('Я', 10001)],
        ] as $payload) {
            $this->jsonRequest('POST', $path . '/messages', $payload, $this->headers);
            self::assertResponseStatusCodeSame(422);
        }
        $this->jsonRequest('POST', $path . '/messages', [
            'version' => 1, 'requestId' => 'limit', 'content' => str_repeat('Я', 10000),
        ], $this->headers);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', $path . '/messages', [
            'version' => 2, 'requestId' => 'over-limit', 'content' => 'Ещё',
        ], $this->headers);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tree_merge_source_too_long', $this->responseString('message'));

        $this->jsonRequest('POST', '/api/v1/trees/' . $this->treeId . '/graph-mutations', [
            'type'   => 'create_first_person',
            'person' => [
                'birthSurname' => ['state' => 'unknown'], 'givenName' => ['state' => 'known', 'value' => 'Ирина'],
                'patronymic'   => ['state' => 'unknown'], 'gender' => 'female',
                'birthDate'    => ['state' => 'unknown'], 'deathDate' => null,
            ],
        ], $this->headers + ['If-Match' => $this->etag]);
        self::assertResponseStatusCodeSame(201);
        /** @var list<array{id: string}> $people */
        $people = $this->responseData()['people'];
        $this->jsonRequest('POST', $this->base(), ['referencePersonId' => null], $this->headers);
        self::assertResponseStatusCodeSame(422);
        $this->jsonRequest('POST', $this->base(), ['referencePersonId' => $people[0]['id']], $this->headers);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($people[0]['id'], $this->responseString('referencePersonId'));
    }

    public function testProtectsTreeDraftsAndNestedResources(): void
    {
        $path = $this->createDraft();
        $this->jsonRequest('GET', $path);
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('GET', $this->base() . '/invalid', headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', $this->base() . '/' . Uuid::v7()->toRfc4122(), headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Other tree'], $this->headers);
        $otherTree = $this->responseString('id');
        $this->jsonRequest('GET', str_replace($this->treeId, $otherTree, $path), headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration([
            'username' => 'other_owner', 'email' => 'other@example.test',
        ]));
        $otherHeaders = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        foreach ([$this->base(), $path] as $url) {
            $this->jsonRequest('GET', $url, headers: $otherHeaders);
            self::assertResponseStatusCodeSame(404);
        }
        $this->jsonRequest('POST', $path . '/cancel', ['version' => 1], $otherHeaders);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', $this->base(), ['referencePersonId' => null], $otherHeaders);
        self::assertResponseStatusCodeSame(404);
    }

    private function base(): string
    {
        return '/api/v1/trees/' . $this->treeId . '/merges';
    }

    private function createDraft(): string
    {
        $this->jsonRequest('POST', $this->base(), ['referencePersonId' => null], $this->headers);
        self::assertResponseStatusCodeSame(201);

        return $this->base() . '/' . $this->responseString('id');
    }
}
