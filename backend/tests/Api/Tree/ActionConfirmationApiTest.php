<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
use App\Tests\Infrastructure\Ai\ResponsesFixture;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ActionConfirmationApiTest extends ApiWebTestCase
{
    private string $treeId;
    private string $path;
    private string $etag;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        FixtureTreeAgent::$useReal = true;
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Actions'], $this->headers);
        $this->treeId = $this->responseString('id');
        $this->etag = (string)$this->client->getResponse()->headers->get('ETag');
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $this->treeId . '/chat',
            ['referencePersonId' => null],
            $this->headers,
        );
        $this->path = '/api/v1/chats/' . $this->responseString('id');
    }

    protected function tearDown(): void
    {
        FixtureTreeAgent::$useReal = false;
        parent::tearDown();
    }

    public function testNativeAgentChainsMutationsFromOneReadAndResumesTheExactApprovedCall(): void
    {
        $calls = 0;
        $personId = null;
        $lastEtag = $this->etag;
        static::getContainer()->set(
            'chat_ai.client',
            new MockHttpClient(
                function (string $method, string $url, array $options) use (&$calls, &$personId, &$lastEtag): MockResponse {
                    ++$calls;
                    $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($body);
                    $messages = ResponsesFixture::messages($body);
                    $last = array_last($messages);
                    self::assertNotNull($last);
                    $result = json_decode($last['content'], true);
                    if (1 === $calls) {
                        self::assertIsArray($body['tools']);
                        $names = array_column($body['tools'], 'name');
                        self::assertNotContains('merge', $names);
                        self::assertNotContains('change_tree', $names);

                        return ResponsesFixture::tool('get_tree_context', ['treeId' => $this->treeId]);
                    }
                    self::assertIsArray($result);
                    if (2 === $calls) {
                        self::assertSame($lastEtag, $result['etag']);

                        return ResponsesFixture::tool(
                            'create_person',
                            [
                                'treeId'     => $this->treeId,
                                'ifMatch'    => $lastEtag,
                                'fields'     => ['givenName' => ['state' => 'known', 'value' => 'Vera']],
                                'connection' => null,
                            ]
                        );
                    }
                    self::assertArrayNotHasKey('error', $result);
                    self::assertNotSame($lastEtag, $result['etag']);
                    $lastEtag = $result['etag'];
                    if (3 === $calls) {
                        $personId = $result['personId'];

                        return ResponsesFixture::tool(
                            'update_person',
                            [
                                'treeId'   => $this->treeId,
                                'ifMatch'  => $lastEtag,
                                'personId' => $personId,
                                'fields'   => ['gender' => 'female'],
                            ]
                        );
                    }
                    if (4 === $calls) {
                        return ResponsesFixture::tool(
                            'create_event',
                            [
                                'treeId'   => $this->treeId,
                                'ifMatch'  => $lastEtag,
                                'personId' => $personId,
                                'event'    => [
                                    'type'        => 'custom',
                                    'date'        => ['state' => 'unknown'],
                                    'title'       => 'A story',
                                    'description' => 'Kept exactly.',
                                ],
                            ]
                        );
                    }

                    return ResponsesFixture::text('Saved all three steps.');
                },
            )
        );
        $this->send();
        self::assertSame('awaiting_confirmation', $this->responseString('status'));
        self::assertSame(2, $calls);
        self::assertCount(1, $this->responseArray('messages'));
        $id = $this->responseString('confirmations', '0', 'id');
        $version = $this->responseInt('version');
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame($id, $this->responseString('confirmations', '0', 'id'));
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'  => $version,
                'decision' => 'approve',
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
        self::assertSame(5, $calls, json_encode($this->responseData(), JSON_THROW_ON_ERROR));
        self::assertSame('idle', $this->responseString('status'));
        self::assertSame('applied', $this->responseString('confirmations', '0', 'status'));
        self::assertSame('Saved all three steps.', $this->responseString('messages', '1', 'content'));
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'  => $version,
                'decision' => 'approve',
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
        self::assertSame(5, $calls);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertCount(1, $this->responseArray('people'));
        self::assertSame($lastEtag, $this->responseString('etag'));
    }

    public function testRejectAndStopLeaveTheTreeUnchanged(): void
    {
        $this->creationProvider('create');
        $this->send();
        $id = $this->responseString('confirmations', '0', 'id');
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'  => $this->responseInt('version'),
                'decision' => 'reject',
            ],
            $this->headers
        );
        self::assertSame('rejected', $this->responseString('confirmations', '0', 'status'));
        $this->send('second');
        $this->jsonRequest('POST', $this->path . '/stop', ['requestId' => 'second'], $this->headers);
        self::assertSame('cancelled', $this->responseString('confirmations', '1', 'status'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseArray('people'));
    }

    public function testConcurrentTreeChangeInvalidatesApprovalWithoutReplacingItsEtag(): void
    {
        $this->creationProvider('create');
        $this->send();
        $id = $this->responseString('confirmations', '0', 'id');
        $version = $this->responseInt('version');
        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $this->treeId,
            ['name' => 'Changed'],
            [
                ...$this->headers,
                'If-Match' => $this->etag,
            ]
        );
        self::assertResponseIsSuccessful();
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'  => $version,
                'decision' => 'approve',
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
        self::assertSame('failed', $this->responseString('confirmations', '0', 'status'));
        self::assertSame(
            'TREE_VERSION_CONFLICT',
            $this->responseString('confirmations', '0', 'result', 'error', 'code'),
        );
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseArray('people'));
    }

    public function testAutoApprovalAppliesOnlyToThisChatAndPermissionsAreVersioned(): void
    {
        $this->jsonRequest(
            'PATCH',
            $this->path . '/permissions',
            [
                'version'                  => 1,
                'autoApprovePersonActions' => true,
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
        $this->creationProvider('auto');
        $this->send();
        self::assertSame('idle', $this->responseString('status'));
        self::assertSame([], $this->responseArray('confirmations'));
        $version = $this->responseInt('version');
        $this->jsonRequest(
            'PATCH',
            $this->path . '/permissions',
            [
                'version'                  => 1,
                'autoApprovePersonActions' => false,
            ],
            $this->headers
        );
        self::assertResponseStatusCodeSame(409);
        $this->jsonRequest('POST', $this->path . '/reset', ['version' => $version], $this->headers);
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $this->treeId . '/chat',
            ['referencePersonId' => null],
            $this->headers,
        );
        self::assertFalse($this->responseData()['autoApprovePersonActions']);
    }

    public function testNewMessageCancelsThePendingCallAndDeniesArgumentReplacement(): void
    {
        $this->creationProvider('create');
        $this->send();
        $id = $this->responseString('confirmations', '0', 'id');
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'   => $this->responseInt('version'),
                'decision'  => 'approve',
                'arguments' => [],
            ],
            $this->headers
        );
        self::assertResponseStatusCodeSame(422);
        $this->send('clarification');
        self::assertSame('cancelled', $this->responseString('confirmations', '0', 'status'));
        self::assertSame('Finished.', $this->responseString('messages', '2', 'content'));
    }

    public function testARoundCannotApplyMultipleMutationsOrSkipApproval(): void
    {
        $calls = 0;
        static::getContainer()->set(
            'chat_ai.client',
            new MockHttpClient(
                function () use (&$calls): MockResponse {
                    if (++$calls > 1) {
                        return ResponsesFixture::text('I must perform one action at a time.');
                    }
                    $items = [];
                    foreach (['one', 'two'] as $id) {
                        $items[] = [
                            'id'        => $id,
                            'type'      => 'function_call',
                            'call_id'   => $id,
                            'name'      => 'create_person',
                            'arguments' => json_encode(
                                [
                                    'treeId'     => $this->treeId,
                                    'ifMatch'    => $this->etag,
                                    'fields'     => [],
                                    'connection' => null,
                                ],
                                JSON_THROW_ON_ERROR
                            ),
                        ];
                    }

                    return ResponsesFixture::stream($items);
                }
            )
        );
        $this->send();
        self::assertSame(2, $calls);
        self::assertSame([], $this->responseArray('confirmations'));
        self::assertSame('idle', $this->responseString('status'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseArray('people'));
    }

    public function testAnotherLoginCannotReadOrApproveTheConfirmation(): void
    {
        $this->creationProvider('private');
        $this->send();
        $id = $this->responseString('confirmations', '0', 'id');
        $version = $this->responseInt('version');
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            [
                'identifier' => 'family_admin',
                'password'   => 'StrongPass1!',
            ]
        );
        $other = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('GET', $this->path, headers: $other);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'  => $version,
                'decision' => 'approve',
            ],
            $other
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidCreationArgumentsAreReportedBeforeRequestingConfirmation(): void
    {
        $calls = 0;
        static::getContainer()->set(
            'chat_ai.client',
            new MockHttpClient(
                function () use (&$calls): MockResponse {
                    if (1 === ++$calls) {
                        return ResponsesFixture::tool(
                            'create_person',
                            [
                                'treeId'     => $this->treeId,
                                'ifMatch'    => $this->etag,
                                'fields'     => ['connection' => ['type' => 'parent']],
                                'connection' => null,
                            ]
                        );
                    }

                    return ResponsesFixture::text('The connection belongs outside the personal fields.');
                }
            )
        );
        $this->send();
        self::assertSame(2, $calls);
        self::assertSame([], $this->responseArray('confirmations'));
        self::assertSame('idle', $this->responseString('status'));
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testLegacyToolStateIsRetiredWithoutLosingVisibleMessages(bool $withProposal): void
    {
        FixtureTreeAgent::$useReal = false;
        $this->send();
        $store = static::getContainer()->get(\App\Application\Chat\GuardedMessageStore::class);
        $uuid = \Symfony\Component\Uid\Uuid::fromString(basename($this->path));
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $uuid);
        $state = $store->load($uuid)->getMetadata()->get(\App\Model\Conversation::METADATA);
        self::assertInstanceOf(\App\Model\Conversation::class, $state);
        if ($withProposal) {
            $state->tools['obsolete'] = new \stdClass();
        }
        $state->toolStateVersion = 0;
        $state->beginAttempt(new \DateTimeImmutable(), 'legacy-running');
        $store->persist($state);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseArray('tools'));
        self::assertSame('idle', $this->responseString('status'));
        self::assertNull($this->responseData()['attemptId']);
        self::assertSame('Add Vera.', $this->responseString('messages', '0', 'content'));
        self::assertSame('Fixture reply.', $this->responseString('messages', '1', 'content'));
    }

    private function creationProvider(string $id): void
    {
        $calls = 0;
        static::getContainer()->set(
            'chat_ai.client',
            new MockHttpClient(
                function () use ($id, &$calls): MockResponse {
                    if (0 === ++$calls % 2) {
                        return ResponsesFixture::text('Finished.');
                    }

                    return ResponsesFixture::stream(
                        [
                            [
                                'id'        => $id . $calls,
                                'type'      => 'function_call',
                                'call_id'   => $id . $calls,
                                'name'      => 'create_person',
                                'arguments' => json_encode(
                                    [
                                        'treeId'     => $this->treeId,
                                        'ifMatch'    => $this->etag,
                                        'fields'     => ['givenName' => ['state' => 'known', 'value' => 'Vera']],
                                        'connection' => null,
                                    ],
                                    JSON_THROW_ON_ERROR
                                ),
                            ],
                        ]
                    );
                }
            )
        );
    }

    public function testApprovalCannotReplayACallSavedBeforeTheParentContractChanged(): void
    {
        $this->creationProvider('old-contract');
        $this->send();
        $id = $this->responseString('confirmations', '0', 'id');
        $store = static::getContainer()->get(\App\Application\Chat\GuardedMessageStore::class);
        $uuid = \Symfony\Component\Uid\Uuid::fromString(basename($this->path));
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $uuid);
        $state = $store->load($uuid)->getMetadata()->get(\App\Model\Conversation::METADATA);
        self::assertInstanceOf(\App\Model\Conversation::class, $state);
        $state->toolStateVersion = 1;
        $store->persist($state);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame('idle', $this->responseString('status'));
        self::assertSame('cancelled', $this->responseString('confirmations', '0', 'status'));
        self::assertSame('Add Vera.', $this->responseString('messages', '0', 'content'));
        $this->jsonRequest(
            'POST',
            $this->path . '/confirmations/' . $id . '/decision',
            [
                'version'  => $this->responseInt('version'),
                'decision' => 'approve',
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->responseString('confirmations', '0', 'status'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseArray('people'));
    }

    private function send(string $requestId = 'first'): void
    {
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        $this->jsonRequest(
            'POST',
            $this->path . '/messages',
            [
                'version'   => $this->responseInt('version'),
                'requestId' => $requestId,
                'content'   => 'Add Vera.',
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
    }
}
