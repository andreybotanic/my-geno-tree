<?php

namespace App\Tests\Api\Tree;

use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
use App\Tests\Infrastructure\Ai\ResponsesFixture;
use App\Tests\Infrastructure\Ai\TestMergeEvents;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class TreeAgentApiTest extends ApiWebTestCase
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

    public function testDocumentSimilaritySearchSourcesPersistWithTheAnswer(): void
    {
        $container = static::getContainer();
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $tree = $em->find(\App\Entity\FamilyTree::class, $this->treeId);
        self::assertInstanceOf(\App\Entity\FamilyTree::class, $tree);
        $document = $container
            ->get(\App\Application\Document\DocumentService::class)
            ->upload(
                $tree,
                null,
                new \Symfony\Component\HttpFoundation\File\UploadedFile(
                    __DIR__ . '/../../Fixtures/Documents/railway-letter.txt',
                    'railway-letter.txt',
                    test: true,
                ),
                $container->get(\App\Application\Tree\TreeVersion::class)->etag($tree),
            );
        $document->transition('ready', new \DateTimeImmutable());
        $em->flush();
        $vectorizer = $container->get(\App\Tests\Infrastructure\Document\DeterministicVectorizer::class);
        self::assertInstanceOf(\App\Tests\Infrastructure\Document\DeterministicVectorizer::class, $vectorizer);
        $candidate = new \Symfony\AI\Store\Document\VectorDocument(
            'chunk',
            $vectorizer->vectorize('railway'),
            new \Symfony\AI\Store\Document\Metadata(
                [
                    'documentId' => $document->getId()->toRfc4122(),
                    'treeId'     => $this->treeId,
                    'generation' => 1,
                    'chunkIndex' => 0,
                    '_text'      => 'Ivan inspected railway tracks at Taiga.',
                    '_source'    => '/tmp/private-loader-file',
                ],
            ),
        );
        $retriever = $this->createStub(\Symfony\AI\Store\RetrieverInterface::class);
        $retriever->method('retrieve')->willReturn([$candidate]);
        $container->set('ai.retriever.documents', $retriever);
        $round = 0;
        $container->set(
            'chat_ai.client',
            new MockHttpClient(
                static function () use (&$round): MockResponse {
                    return 1 === ++$round
                        ? ResponsesFixture::tool('similarity_search', ['searchTerm' => 'railway occupation'])
                        : ResponsesFixture::text('railway-letter.txt: Ivan inspected railway tracks at Taiga.');
                },
            ),
        );
        FixtureTreeAgent::$useReal = true;
        $this->send('search-documents');
        self::assertSame(2, $round);
        self::assertSame('railway-letter.txt', $this->responseString('messages', '1', 'documentSources', '0', 'name'));
        self::assertSame(
            '/trees/' . $this->treeId . '/documents/' . $document->getId() . '/download',
            $this->responseString('messages', '1', 'documentSources', '0', 'downloadUrl'),
        );
        self::assertStringNotContainsString('/tmp/', json_encode($this->responseData(), JSON_THROW_ON_ERROR));
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame(
            'Ivan inspected railway tracks at Taiga.',
            $this->responseString('messages', '1', 'documentSources', '0', 'excerpt'),
        );
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
                self::assertContains(
                    $packet['commentary'],
                    [
                        null,
                        'Проверяю семью.',
                        'Уточняю результат.',
                    ]
                );
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
