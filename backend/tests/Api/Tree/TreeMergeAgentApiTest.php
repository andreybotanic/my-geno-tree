<?php

namespace App\Tests\Api\Tree;

use App\Entity\AuthSession;
use App\Entity\GenealogicalEvent;
use App\Entity\Value\EventSource;
use App\Model\Conversation;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
use App\Tests\Infrastructure\Ai\ResponsesFixture;
use App\Tests\Infrastructure\Ai\TestMergeEvents;
use App\Tool\TreeMergeTool;
use App\Tool\TreeMergeToolSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class TreeMergeAgentApiTest extends ApiWebTestCase
{
    private string $treeId;
    private string $path;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        FixtureTreeAgent::$handler = null;
        FixtureTreeAgent::$useReal = false;
        TestMergeEvents::$updates = [];
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Local agent'], $this->headers);
        $this->treeId = $this->responseString('id');
        $base = '/api/v1/trees/' . $this->treeId . '/chat';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $this->headers);
        $this->path = '/api/v1/chats/' . $this->responseString('id');
    }

    protected function tearDown(): void
    {
        FixtureTreeAgent::$useReal = false;
        FixtureTreeAgent::$handler = null;
        parent::tearDown();
    }

    public function testConfiguredSymfonyAgentExecutesTheCompleteToolLoopWithoutExposingCredentials(): void
    {
        $calls = 0;
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$calls): MockResponse {
                ++$calls;
                self::assertSame('POST', $method);
                self::assertSame('http://cli-proxy-api:8317/v1/responses', $url);
                $proxyKey = $_ENV['CLI_PROXY_API_KEY'];
                self::assertIsString($proxyKey);
                self::assertContains('Authorization: Bearer ' . $proxyKey, $options['headers']);
                /**
                 * @var array{
                 *     model: string, max_output_tokens: int, stream: bool,
                 *     messages: list<array{
                 *         role: string,
                 *         content: string
                 *     }>,
                 *     tools: list<array{
                 *         name: string,
                 *         parameters: array<string, mixed>
                 *     }>
                 * } $body
                 */
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $body['messages'] = ResponsesFixture::messages($body);
                self::assertSame('gpt-5.6-luna', $body['model']);
                self::assertSame(16384, $body['max_output_tokens']);
                self::assertTrue($body['stream']);
                self::assertStringNotContainsString($proxyKey, $options['body']);
                self::assertStringNotContainsString($this->headers['Authorization'], $options['body']);
                self::assertSame('user', $body['messages'][1]['role']);
                self::assertStringEndsWith("\nVera is my mother.", $body['messages'][1]['content']);
                $messageId = substr(explode("\n", $body['messages'][1]['content'])[0], strlen('messageId: '));
                self::assertTrue(Uuid::isValid($messageId));
                $offset = strpos($body['messages'][0]['content'], '{"promptVersion"');
                self::assertNotFalse($offset);
                $input = json_decode(
                    substr($body['messages'][0]['content'], $offset),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                self::assertIsArray($input);
                self::assertArrayNotHasKey('accessToken', $input);
                self::assertArrayNotHasKey('attemptId', $input);
                self::assertArrayNotHasKey('userId', $input);
                self::assertSame($this->treeId, $input['treeId']);
                $schemas = array_column($body['tools'], 'parameters', 'name');
                self::assertCount(9, $schemas);
                $searchProperties = $schemas['find_people']['properties'];
                self::assertIsArray($searchProperties);
                $querySchema = $searchProperties['query'];
                self::assertIsArray($querySchema);
                self::assertSame(2, $querySchema['minLength']);
                self::assertSame(255, $querySchema['maxLength']);
                self::assertArrayNotHasKey('find_trees', $schemas);
                self::assertArrayNotHasKey('reply_to_tree_merge', $schemas);
                $prepareSchema = $schemas['merge'];
                self::assertIsArray($prepareSchema['properties']);
                self::assertSame(TreeMergeToolSchema::PROPOSAL, $prepareSchema['properties']['proposal']);
                foreach ($schemas as $schema) {
                    self::assertIsArray($schema['properties']);
                    foreach (['accessToken', 'attemptId', 'userId', 'sessionId'] as $private) {
                        self::assertArrayNotHasKey($private, $schema['properties']);
                    }
                }
                if (1 === $calls) {
                    return $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]);
                }
                $lastIndex = array_key_last($body['messages']);
                self::assertNotNull($lastIndex);
                self::assertArrayHasKey($lastIndex, $body['messages']);
                $result = json_decode($body['messages'][$lastIndex]['content'], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                if (2 === $calls) {
                    self::assertSame(0, $result['personCount']);
                    self::assertArrayNotHasKey('sources', $input);

                    return $this->toolResponse(
                        'merge',
                        [
                            'treeId'   => $this->treeId,
                            'proposal' => [
                                'people' => [
                                    [
                                        'id'               => 'v',
                                        'existingPersonId' => null,
                                        'resolution'       => 'new',
                                        'lifeStatus'       => 'alive',
                                        'fields'           => [
                                            'givenName' => ['state' => 'known', 'value' => 'Vera'],
                                            'gender'    => 'female',
                                        ],
                                        'evidence' => [
                                            ['messageId' => $messageId, 'quote' => 'Vera is my mother.'],
                                        ],
                                    ],
                                ],
                                'parents'   => [],
                                'marriages' => [],
                                'questions' => [],
                            ],
                        ],
                    );
                }
                self::assertSame(1, $result['newPeople']);
                $previews = 0;
                foreach (TestMergeEvents::updates() as $event) {
                    $packet = json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($packet);
                    $view = $packet['conversation'] ?? null;
                    if ('conversation' === $packet['kind'] && is_array($view)) {
                        self::assertIsArray($view['tools']);
                        $pending = $view['tools']['merge'] ?? null;
                        if (is_array($pending)) {
                            ++$previews;
                            self::assertSame('processing', $view['status']);
                            self::assertSame('ready', $pending['state']);
                            self::assertIsArray($view['messages']);
                            self::assertCount(1, $view['messages']);
                        }
                    }
                }
                self::assertSame(1, $previews, 'Merge must publish the preview before the final model answer.');
                self::assertSame('ready', $result['state']);

                return $this->textResponse("I prepared Vera's profile.\nPlease review the preview.");
            },
        );
        static::getContainer()->set('chat_ai.client', $http);
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(3, $calls);
        self::assertSame(['input' => 300, 'output' => 30, 'total' => 330], $this->responseData()['tokenUsage']);
        self::assertSame(
            $this->responseData()['tokenUsage'],
            $this->responseArray('messages', '1', 'responseMetrics', 'tokenUsage'),
        );
        self::assertGreaterThanOrEqual(0, $this->responseInt('messages', '1', 'responseMetrics', 'durationMs'));
        $budget = $this->responseData()['tokenBudget'];
        self::assertIsArray($budget);
        self::assertIsInt($budget['limit']);
        self::assertSame($budget['limit'] - 330, $budget['remaining']);
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame(
            "I prepared Vera's profile.\nPlease review the preview.",
            $this->responseString('messages', '1', 'content'),
        );
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame(
            'Vera',
            $this->responseString('tools', 'merge', 'proposal', 'people', '0', 'fields', 'givenName', 'value'),
        );
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
    }

    public function testOrdinaryMessagesRemainTextAndAreIncludedInTheNextTurn(): void
    {
        $text = "Here is my answer.\n\n" . str_repeat('Plain text is displayed directly. ', 12);
        $calls = 0;
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    function (string $method, string $url, array $options) use (&$calls, $text): MockResponse {
                        if (++$calls > 1) {
                            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                            self::assertIsArray($body);
                            $body['messages'] = ResponsesFixture::messages($body);
                            self::assertIsArray($body['messages']);
                            self::assertSame(
                                ['system', 'user', 'assistant', 'user'],
                                array_column($body['messages'], 'role'),
                            );
                            self::assertIsArray($body['messages'][2]);
                            self::assertSame($text, $body['messages'][2]['content']);
                        }

                        return $this->textResponse($text);
                    },
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame($text, $this->responseString('messages', '1', 'content'));
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        $this->send('followup');
        self::assertSame(2, $calls);
    }

    public function testResponseMetricsStayWithTheirAnswerAcrossLaterTurnsAndReloads(): void
    {
        $calls = 0;
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    static function (string $method, string $url, array $options) use (&$calls): MockResponse {
                        self::assertStringNotContainsString('responseMetrics', $options['body']);

                        return ResponsesFixture::text('Answer ' . ++$calls, $calls * 11);
                    },
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send('metrics-first');
        $first = $this->responseArray('messages', '1', 'responseMetrics');
        self::assertSame(11, $this->responseInt('messages', '1', 'responseMetrics', 'tokenUsage', 'total'));
        $this->send('metrics-second');
        $second = $this->responseArray('messages', '3', 'responseMetrics');
        self::assertSame(22, $this->responseInt('messages', '3', 'responseMetrics', 'tokenUsage', 'total'));
        self::assertSame($first, $this->responseArray('messages', '1', 'responseMetrics'));
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame($first, $this->responseArray('messages', '1', 'responseMetrics'));
        self::assertSame($second, $this->responseArray('messages', '3', 'responseMetrics'));
        self::assertArrayNotHasKey('responseMetrics', $this->responseArray('messages', '0'));
    }

    public function testDeniedReadsAndInvalidMergeReturnDiagnosticsWithoutPreviewEvents(): void
    {
        $calls = 0;
        $http = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$calls): MockResponse {
                if (1 === ++$calls) {
                    return $this->toolResponse('get_tree_context', ['treeId' => Uuid::v7()->toRfc4122()]);
                }
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $body['messages'] = ResponsesFixture::messages($body);
                self::assertIsArray($body['messages']);
                $last = array_last($body['messages']);
                self::assertIsArray($last);
                self::assertIsString($last['content']);
                if (2 === $calls) {
                    self::assertStringContainsString('error', $last['content']);

                    return $this->toolResponse(
                        'merge',
                        ['treeId' => $this->treeId, 'proposal' => ['people' => 'invalid']],
                    );
                }
                $result = json_decode($last['content'], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertArrayHasKey('error', $result);

                return $this->textResponse('Please describe the person you want to add.');
            },
        );
        static::getContainer()->set('chat_ai.client', $http);
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(3, $calls);
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        self::assertNull($this->responseData()['commentary']);
        self::assertSame(
            'Please describe the person you want to add.',
            $this->responseString('messages', '1', 'content'),
        );
        foreach (TestMergeEvents::updates() as $event) {
            $packet = json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($packet);
            if ('conversation' === $packet['kind']) {
                self::assertIsArray($packet['conversation']);
                self::assertIsArray($packet['conversation']['tools']);
                self::assertNull($packet['conversation']['tools']['merge'] ?? null);
            }
        }
    }

    public function testDiscardDuringTheNativeToolLoopCannotResurrectTheCachedProposal(): void
    {
        $calls = 0;
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    function () use (&$calls): MockResponse {
                        ++$calls;
                        if (1 === $calls) {
                            return $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]);
                        }
                        $request = static::getContainer()->get(RequestStack::class)->getCurrentRequest();
                        self::assertNotNull($request);
                        $draft = $request->attributes->get(Conversation::class);
                        self::assertInstanceOf(Conversation::class, $draft);
                        if (2 === $calls) {
                            return $this->toolResponse(
                                'merge',
                                [
                                    'treeId'   => $this->treeId,
                                    'proposal' => [
                                        'people' => [
                                            [
                                                'id'               => 'v',
                                                'existingPersonId' => null,
                                                'resolution'       => 'new',
                                                'lifeStatus'       => 'alive',
                                                'fields'           => [
                                                    'givenName' => ['state' => 'known', 'value' => 'Vera'],
                                                    'gender'    => 'female',
                                                ],
                                                'evidence' => [
                                                    [
                                                        'messageId' => FixtureTreeAgent::messages($draft)[0]['id'],
                                                        'quote'     => 'Vera is my mother.',
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'parents'   => [],
                                        'marriages' => [],
                                        'questions' => [],
                                    ],
                                ],
                            );
                        }
                        self::assertSame(3, $calls);
                        $tree = static::getContainer()->get(\App\Repository\FamilyTreeRepository::class)->find(
                            $this->treeId,
                        );
                        self::assertNotNull($tree);
                        $proposal = $draft->tools['merge'];
                        self::assertInstanceOf(\App\Model\TreeMergeProposal::class, $proposal);
                        static::getContainer()->get(TreeMergeTool::class)->proposal(
                            $tree,
                            $proposal->id->toRfc4122(),
                            'discard',
                            ['version' => $proposal->version],
                        );

                        return $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]);
                    },
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(3, $calls);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        self::assertArrayNotHasKey('merge', $this->responseArray('tools'));
        self::assertSame('Vera is my mother.', $this->responseString('messages', '0', 'content'));
        self::assertCount(1, $this->responseArray('messages'));
    }

    public function testProviderFailureRestoresRequestScope(): void
    {
        static::getContainer()->set(
            'chat_ai.client',
            new MockHttpClient(static fn () => throw new \RuntimeException('offline')),
        );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame('provider_unavailable', $this->responseString('messages', '1', 'failureCode'));
        self::assertNull(static::getContainer()->get(RequestStack::class)->getCurrentRequest());
    }

    public function testMergeRequiresAnActiveAttemptAndReadsRespectTheSelectedTree(): void
    {
        $result = (static::getContainer()->get(TreeMergeTool::class))($this->treeId, []);
        self::assertIsArray($result['error']);
        self::assertSame('CHAT_AGENT_REQUIRED', $result['error']['code']);
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Another owned tree'], $this->headers);
        $other = $this->responseString('id');
        $calls = 0;
        static::getContainer()->set(
            'chat_ai.client',
            new MockHttpClient(
                function (string $method, string $url, array $options) use ($other, &$calls): MockResponse {
                    ++$calls;
                    if ($calls > 1) {
                        /** @var array{messages: non-empty-list<array{content: string}>} $body */
                        $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                        self::assertIsArray($body);
                        $body['messages'] = ResponsesFixture::messages($body);
                        $last = array_last($body['messages']);
                        self::assertNotNull($last);
                        /** @var array{error?: array{code: string}, personCount?: int} $result */
                        $result = json_decode($last['content'], true, flags: JSON_THROW_ON_ERROR);
                        if ($calls <= 4) {
                            self::assertSame('TREE_NOT_FOUND', $result['error']['code'] ?? null);
                        } else {
                            self::assertSame(0, $result['personCount'] ?? null);
                        }
                    }

                    return match ($calls) {
                        1 => $this->toolResponse('get_tree_context', ['treeId' => $other]),
                        2 => $this->toolResponse('find_people', ['treeId' => $other, 'query' => 'Vera']),
                        3 => $this->toolResponse(
                            'get_person_context',
                            ['treeId' => $other, 'personId' => Uuid::v7()->toRfc4122()],
                        ),
                        13      => $this->textResponse('All reads completed.'),
                        default => $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]),
                    };
                },
            ),
        );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(13, $calls);
        self::assertSame('All reads completed.', $this->responseString('messages', '1', 'content'));
    }

    public function testLogoutRevokesToolsEvenWhenTheRequestRetainsItsSecurityToken(): void
    {
        FixtureTreeAgent::$handler = function (
            Conversation $conversation,
            string $attempt,
            TreeMergeTool $merge,
        ): string {
            $request = static::getContainer()->get(RequestStack::class)->getMainRequest();
            self::assertNotNull($request);
            $session = $request->attributes->get(AuthSession::class);
            self::assertInstanceOf(AuthSession::class, $session);
            $session->revoke(new \DateTimeImmutable());
            static::getContainer()->get(EntityManagerInterface::class)->flush();
            $result = $merge($this->treeId, []);
            self::assertIsArray($result['error']);
            self::assertSame('CHAT_AGENT_REQUIRED', $result['error']['code']);

            return 'Late answer.';
        };
        $this->send();
        self::assertCount(1, $this->responseArray('messages'));
    }

    public function testCommentaryIsPublishedBeforeTheToolAndNeverBecomesTheFinalAnswer(): void
    {
        $calls = 0;
        $observed = 0;
        static::getContainer()
            ->get('event_dispatcher')
            ->addListener(
                \Symfony\AI\Agent\Toolbox\Event\ToolCallRequested::class,
                static function () use (&$observed): void {
                    $packets = array_map(
                        static function ($event): array {
                            $packet = json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR);
                            self::assertIsArray($packet);

                            return $packet;
                        },
                        TestMergeEvents::updates(),
                    );
                    $latest = array_last(
                        array_filter(
                            $packets,
                            static fn ($packet) => 'progress' === $packet['kind'],
                        ),
                    );
                    self::assertNotNull($latest);
                    self::assertSame(
                        ['Проверяю семью.', 'Уточняю результат.'][$observed],
                        $latest['commentary'],
                    );
                    ++$observed;
                },
            );
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    function () use (&$calls): MockResponse {
                        if (++$calls > 2) {
                            return $this->textResponse('The family is empty.');
                        }

                        return ResponsesFixture::stream(
                            [
                                [
                                    'id'      => 'empty',
                                    'type'    => 'message',
                                    'role'    => 'assistant',
                                    'phase'   => 'commentary',
                                    'content' => [['type' => 'output_text', 'text' => ' ']],
                                ],
                                [
                                    'id'      => 'comment',
                                    'type'    => 'message',
                                    'role'    => 'assistant',
                                    'phase'   => 'commentary',
                                    'content' => [
                                        [
                                            'type' => 'output_text',
                                            'text' => 1 === $calls ? 'Проверяю семью.' : 'Уточняю результат.',
                                        ],
                                    ],
                                ],
                                [
                                    'id'        => 'read' . $calls,
                                    'type'      => 'function_call',
                                    'call_id'   => 'read' . $calls,
                                    'name'      => 'get_tree_context',
                                    'arguments' => json_encode(['treeId' => $this->treeId], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        );
                    },
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(2, $observed);
        self::assertSame(3, $calls);
        self::assertSame('The family is empty.', $this->responseString('messages', '1', 'content'));
        self::assertNull($this->responseData()['commentary']);
        $commentarySeen = false;
        foreach (TestMergeEvents::updates() as $event) {
            $packet = json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($packet);
            if ('progress' === $packet['kind']) {
                self::assertArrayNotHasKey('progress', $packet);
                self::assertStringNotContainsString('get_tree_context', $event->getData());
                self::assertContains($packet['commentary'], [null, 'Проверяю семью.', 'Уточняю результат.']);
                if ($commentarySeen) {
                    self::assertIsString($packet['commentary']);
                    self::assertNotSame('', trim($packet['commentary']));
                }
                $commentarySeen = $commentarySeen || null !== $packet['commentary'];
            }
        }
    }

    public function testTruncatedStreamDoesNotSaveAPartialAnswer(): void
    {
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    new MockResponse(
                        'data: {"type":"response.output_text.delta","delta":"Partial answer"}' . "\n\n"
                            . 'data: {"type":"response.incomplete","response":{"incomplete_details":{"reason":"max_output_tokens"}}}'
                            . "\n\n",
                        ['response_headers' => ['content-type: text/event-stream']],
                    ),
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame('output_limit', $this->responseString('messages', '1', 'failureCode'));
        self::assertSame('', $this->responseString('messages', '1', 'content'));
        self::assertSame('error', $this->responseString('status'));
    }

    public function testStreamUsageIsDebitedBeforeALaterFailure(): void
    {
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    new MockResponse(
                        'data: {"type":"response.in_progress","response":{"usage":{"input_tokens":10,"output_tokens":2,"total_tokens":12}}}'
                            . "\n\n"
                            . 'data: {"type":"error","error":{"code":"rate_limit_exceeded","message":"busy"}}' . "\n\n",
                        ['response_headers' => ['content-type: text/event-stream']],
                    ),
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame('provider_rate_limit', $this->responseString('messages', '1', 'failureCode'));
        self::assertSame(12, $this->responseInt('tokenUsage', 'total'));
    }

    public function testModelExceptionsHaveActionableFailureCodes(): void
    {
        $errors = [
            [new \Symfony\AI\Platform\Exception\RateLimitExceededException(), 'provider_rate_limit'],
            [new \Symfony\AI\Platform\Exception\ExceedContextSizeException('large'), 'context_limit'],
            [new \Symfony\AI\Platform\Exception\MaxOutputTokensException('large'), 'output_limit'],
            [new \Symfony\AI\Agent\Exception\MaxIterationsExceededException(16), 'tool_limit'],
            [new \Symfony\AI\Platform\Exception\ContentFilterException('blocked'), 'provider_refused'],
        ];
        foreach ($errors as $index => [$error, $code]) {
            FixtureTreeAgent::$handler = static fn () => throw $error;
            $this->send('failure-' . $index);
            self::assertSame($code, $this->responseString('messages', (string)($index * 2 + 1), 'failureCode'));
        }
    }

    public function testSearchSchemaRejectsAnInvalidQueryBeforeExecutingTheTool(): void
    {
        $calls = 0;
        static::getContainer()
            ->set(
                'chat_ai.client',
                new MockHttpClient(
                    function ($method, $url, $options) use (&$calls): MockResponse {
                        if (1 === ++$calls) {
                            return $this->toolResponse('find_people', ['treeId' => $this->treeId, 'query' => 'a']);
                        }
                        $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                        self::assertIsArray($body);
                        $messages = ResponsesFixture::messages($body);
                        $last = array_last($messages);
                        self::assertNotNull($last);
                        self::assertStringContainsString('query', $last['content']);

                        return $this->textResponse('Please provide at least two characters.');
                    },
                ),
            );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(2, $calls);
        self::assertSame('Please provide at least two characters.', $this->responseString('messages', '1', 'content'));
    }

    public function testGenericChatChangesApplyImmediatelyWithoutNewPeople(): void
    {
        $this->checkGenericChange(false);
    }

    public function testMixedChatPacketChangesNothingBeforeReview(): void
    {
        $this->checkGenericChange(true);
    }

    private function checkGenericChange(bool $newPerson): void
    {
        $container = static::getContainer();
        $tree = $container->get(\App\Repository\FamilyTreeRepository::class)->find($this->treeId);
        self::assertInstanceOf(\App\Entity\FamilyTree::class, $tree);
        $created = $container
            ->get(\App\Application\Tree\ChangeSet\TreeChangeSetExecutor::class)
            ->execute(
                $tree,
                $container
                    ->get(\App\Application\Tree\ChangeSet\TreeChangeSetFactory::class)
                    ->create(
                        $tree,
                        [
                            ['type' => 'create_person', 'id' => 'mother', 'fields' => ['gender' => 'female']],
                        ],
                    ),
                $container->get(\App\Application\Tree\TreeVersion::class)->etag($tree),
            );
        $id = $created['personIds']['mother'];
        $calls = 0;
        $container->set(
            'chat_ai.client',
            new MockHttpClient(
                function ($method, $url, $options) use (&$calls, $id, $newPerson): MockResponse {
                    ++$calls;
                    if (1 === $calls) {
                        return $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]);
                    }
                    $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($body);
                    $messages = ResponsesFixture::messages($body);
                    $last = array_last($messages);
                    self::assertNotNull($last);
                    $result = json_decode($last['content'], true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($result);
                    if (2 === $calls) {
                        $messageId = substr(explode("\n", $messages[1]['content'])[0], strlen('messageId: '));
                        $evidence = [['messageId' => $messageId, 'quote' => 'Vera is my mother.']];
                        $operations = [];
                        if ($newPerson) {
                            $operations[] = [
                                'type'     => 'create_person',
                                'id'       => 'child',
                                'fields'   => [],
                                'evidence' => $evidence,
                            ];
                            $operations[] = [
                                'type'     => 'add_parent_link',
                                'parent'   => $id,
                                'child'    => 'child',
                                'role'     => 'mother',
                                'evidence' => $evidence,
                            ];
                        }
                        $operations[] = [
                            'type'      => 'create_events',
                            'personIds' => $newPerson ? [$id, 'child'] : [$id],
                            'event'     => [
                                'type'        => 'custom',
                                'date'        => ['state' => 'unknown'],
                                'title'       => 'Family occasion',
                                'description' => null,
                            ],
                            'evidence' => $evidence,
                        ];

                        return $this->toolResponse(
                            'change_tree',
                            ['treeId' => $this->treeId, 'ifMatch' => $result['etag'], 'operations' => $operations],
                        );
                    }
                    self::assertSame($newPerson ? 'ready' : 'applied', $result['state']);

                    return $this->textResponse('Done.');
                },
            ),
        );
        FixtureTreeAgent::$useReal = true;
        $this->send();
        self::assertSame(3, $calls);
        $em = $container->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(\App\Entity\Person::class)->count());
        self::assertSame(
            $newPerson ? 0 : 1,
            $em->getRepository(GenealogicalEvent::class)->count(['source' => EventSource::MANUAL]),
        );
        if ($newPerson) {
            $proposal = $this->responseArray('tools', 'merge');
            self::assertIsString($proposal['id']);
            self::assertIsString($proposal['treeEtag']);
            $this->jsonRequest(
                'POST',
                '/api/v1/trees/' . $this->treeId . '/merge-proposals/' . $proposal['id'] . '/apply',
                ['version' => $proposal['version']],
                $this->headers + ['If-Match' => $proposal['treeEtag']],
            );
            self::assertResponseIsSuccessful();
            self::assertSame(2, $em->getRepository(\App\Entity\Person::class)->count());
            self::assertSame(
                2,
                $em->getRepository(GenealogicalEvent::class)->count(['source' => EventSource::MANUAL]),
            );
        } else {
            self::assertArrayNotHasKey('merge', $this->responseArray('tools'));
            /** @var list<array<string, mixed>> $packets */
            $packets = array_map(
                static fn ($event): mixed => json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR),
                TestMergeEvents::updates(),
            );
            self::assertNotEmpty(
                array_filter($packets, static fn (array $packet): bool => 'change_tree' === ($packet['tool'] ?? null)),
            );
        }
    }

    /** @param array<string, mixed> $arguments */
    private function toolResponse(string $name, array $arguments): MockResponse
    {
        return ResponsesFixture::tool($name, $arguments);
    }

    private function textResponse(string $text): MockResponse
    {
        return ResponsesFixture::text($text);
    }

    private function send(string $requestId = 'family'): void
    {
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        $this->jsonRequest(
            'POST',
            $this->path . '/messages',
            [
                'version'   => $this->responseInt('version'),
                'requestId' => $requestId,
                'content'   => 'followup' === $requestId ? 'Explain more.' : 'Vera is my mother.',
            ],
            $this->headers
        );
        self::assertResponseIsSuccessful();
    }
}
