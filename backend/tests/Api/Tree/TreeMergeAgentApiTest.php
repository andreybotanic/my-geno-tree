<?php

namespace App\Tests\Api\Tree;

use App\Api\ApiException;
use App\Application\TreeChat\TreeAssistant;
use App\Entity\AuthSession;
use App\Model\Conversation;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
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
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => 1, 'requestId' => 'family', 'content' => 'Vera is my mother.'], $this->headers);
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
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame('POST', $method);
            self::assertSame('http://cli-proxy-api:8317/v1/chat/completions', $url);
            $proxyKey = $_ENV['CLI_PROXY_API_KEY'];
            self::assertIsString($proxyKey);
            self::assertContains('Authorization: Bearer ' . $proxyKey, $options['headers']);
            /** @var array{model: string, messages: list<array{role: string, content: string}>, tools: list<array{function: array{name: string, parameters: array<string, mixed>}}>} $body */
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('gpt-5.6-luna', $body['model']);
            self::assertStringNotContainsString($proxyKey, $options['body']);
            self::assertStringNotContainsString($this->headers['Authorization'], $options['body']);
            self::assertSame('user', $body['messages'][1]['role']);
            self::assertStringEndsWith("\nVera is my mother.", $body['messages'][1]['content']);
            $messageId = substr(explode("\n", $body['messages'][1]['content'])[0], strlen('messageId: '));
            self::assertTrue(Uuid::isValid($messageId));
            $offset = strpos($body['messages'][0]['content'], '{"promptVersion"');
            self::assertNotFalse($offset);
            $input = json_decode(substr($body['messages'][0]['content'], $offset), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($input);
            self::assertArrayNotHasKey('accessToken', $input);
            self::assertArrayNotHasKey('attemptId', $input);
            self::assertArrayNotHasKey('userId', $input);
            self::assertSame($this->treeId, $input['treeId']);
            $schemas = array_column(array_column($body['tools'], 'function'), 'parameters', 'name');
            self::assertCount(4, $schemas);
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

                return $this->toolResponse('merge', ['treeId' => $this->treeId, 'proposal' => [
                    'people' => [['id' => 'v', 'existingPersonId' => null, 'resolution' => 'new', 'lifeStatus' => 'alive',
                        'fields'       => ['givenName' => ['state' => 'known', 'value' => 'Vera'], 'gender' => 'female'],
                        'evidence'     => [['messageId' => $messageId, 'quote' => 'Vera is my mother.']]]],
                    'parents' => [], 'marriages' => [], 'questions' => [],
                ]]);
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
        });
        static::getContainer()->set('chat_ai.client', $http);
        FixtureTreeAgent::$useReal = true;
        $this->process();
        self::assertSame(3, $calls);
        self::assertSame(['input' => 300, 'output' => 30, 'total' => 330], $this->responseData()['tokenUsage']);
        $budget = $this->responseData()['tokenBudget'];
        self::assertIsArray($budget);
        self::assertIsInt($budget['limit']);
        self::assertSame($budget['limit'] - 330, $budget['remaining']);
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame("I prepared Vera's profile.\nPlease review the preview.", $this->responseString('messages', '1', 'content'));
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame('Vera', $this->responseString('tools', 'merge', 'proposal', 'people', '0', 'fields', 'givenName', 'value'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
    }

    public function testOrdinaryMessagesRemainTextAndAreIncludedInTheNextTurn(): void
    {
        $text = "Here is my answer.\n\n" . str_repeat('Plain text is displayed directly. ', 12);
        $calls = 0;
        static::getContainer()->set('chat_ai.client', new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, $text): MockResponse {
            if (++$calls > 1) {
                $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertIsArray($body['messages']);
                self::assertSame(['system', 'user', 'assistant', 'user'], array_column($body['messages'], 'role'));
                self::assertIsArray($body['messages'][2]);
                self::assertSame($text, $body['messages'][2]['content']);
            }

            return $this->textResponse($text);
        }));
        FixtureTreeAgent::$useReal = true;
        $this->process();
        self::assertSame($text, $this->responseString('messages', '1', 'content'));
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => $this->responseData()['version'], 'requestId' => 'followup', 'content' => 'Explain more.'], $this->headers);
        $this->process('followup');
        self::assertSame(2, $calls);
    }

    public function testDeniedReadsAndInvalidMergeReturnDiagnosticsWithoutPreviewEvents(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            if (1 === ++$calls) {
                return $this->toolResponse('get_tree_context', ['treeId' => Uuid::v7()->toRfc4122()]);
            }
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertIsArray($body['messages']);
            $last = array_last($body['messages']);
            self::assertIsArray($last);
            self::assertIsString($last['content']);
            if (2 === $calls) {
                self::assertStringContainsString('error', $last['content']);

                return $this->toolResponse('merge', ['treeId' => $this->treeId, 'proposal' => ['people' => 'invalid']]);
            }
            $result = json_decode($last['content'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertArrayHasKey('error', $result);

            return $this->textResponse('Please describe the person you want to add.');
        });
        static::getContainer()->set('chat_ai.client', $http);
        FixtureTreeAgent::$useReal = true;
        $this->process();
        self::assertSame(3, $calls);
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        self::assertSame('Please describe the person you want to add.', $this->responseString('messages', '1', 'content'));
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
        static::getContainer()->set('chat_ai.client', new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;
            if (1 === $calls) {
                return $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]);
            }
            $request = static::getContainer()->get(RequestStack::class)->getCurrentRequest();
            self::assertNotNull($request);
            $draft = $request->attributes->get(Conversation::class);
            self::assertInstanceOf(Conversation::class, $draft);
            if (2 === $calls) {
                return $this->toolResponse('merge', ['treeId' => $this->treeId, 'proposal' => [
                    'people' => [['id' => 'v', 'existingPersonId' => null, 'resolution' => 'new', 'lifeStatus' => 'alive',
                        'fields'       => ['givenName' => ['state' => 'known', 'value' => 'Vera'], 'gender' => 'female'],
                        'evidence'     => [['messageId' => FixtureTreeAgent::messages($draft)[0]['id'], 'quote' => 'Vera is my mother.']]]],
                    'parents' => [], 'marriages' => [], 'questions' => [],
                ]]);
            }
            self::assertSame(3, $calls);
            $tree = static::getContainer()->get(\App\Repository\FamilyTreeRepository::class)->find($this->treeId);
            self::assertNotNull($tree);
            $proposal = $draft->tools['merge'];
            self::assertInstanceOf(\App\Model\TreeMergeProposal::class, $proposal);
            static::getContainer()->get(TreeAssistant::class)->proposal($tree, $proposal->id->toRfc4122(), 'discard', ['version' => $proposal->version]);

            return $this->toolResponse('get_tree_context', ['treeId' => $this->treeId]);
        }));
        FixtureTreeAgent::$useReal = true;
        $this->process();
        self::assertSame(3, $calls);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        self::assertArrayNotHasKey('merge', $this->responseArray('tools'));
        self::assertSame('Vera is my mother.', $this->responseString('messages', '0', 'content'));
        self::assertCount(1, $this->responseArray('messages'));
    }

    public function testProviderFailureRestoresRequestScope(): void
    {
        static::getContainer()->set('chat_ai.client', new MockHttpClient(static fn () => throw new \RuntimeException('offline')));
        FixtureTreeAgent::$useReal = true;
        $this->process();
        self::assertSame('provider_unavailable', $this->responseString('messages', '1', 'failureCode'));
        self::assertNull(static::getContainer()->get(RequestStack::class)->getCurrentRequest());
    }

    public function testMergeRequiresAnActiveAttemptAndReadsRespectTheSelectedTree(): void
    {
        $result = (static::getContainer()->get(TreeMergeTool::class))($this->treeId, []);
        self::assertIsArray($result['error']);
        self::assertSame('CHAT_AGENT_REQUIRED', $result['error']['code']);
        FixtureTreeAgent::$handler = static function (Conversation $conversation, string $attempt, TreeMergeTool $merge, \App\Tool\TreeReadTools $reads): string {
            $foreign = Uuid::v7()->toRfc4122();
            foreach ([fn () => $reads->tree($foreign), fn () => $reads->find($foreign, 'Vera'), fn () => $reads->person($foreign, Uuid::v7()->toRfc4122())] as $operation) {
                try {
                    $operation();
                    self::fail('Foreign tree read must fail');
                } catch (ApiException $error) {
                    self::assertSame('TREE_NOT_FOUND', $error->getErrorCode());
                }
            }
            self::assertArrayHasKey('error', $merge($foreign, []));
            for ($i = 0; $i < 8; ++$i) {
                $reads->tree($conversation->context['treeId'] ?? '');
            }
            try {
                $reads->tree($conversation->context['treeId'] ?? '');
                self::fail('Read budget must be bounded');
            } catch (ApiException $error) {
                self::assertSame('TREE_CONTEXT_LIMIT', $error->getErrorCode());
            }

            return 'Read limit reached.';
        };
        $this->process();
        self::assertSame('Read limit reached.', $this->responseString('messages', '1', 'content'));
    }

    public function testLogoutRevokesToolsEvenWhenTheRequestRetainsItsSecurityToken(): void
    {
        FixtureTreeAgent::$handler = function (Conversation $conversation, string $attempt, TreeMergeTool $merge): string {
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
        $this->process();
        self::assertCount(1, $this->responseArray('messages'));
    }

    /** @param array<string, mixed> $arguments */
    private function toolResponse(string $name, array $arguments): MockResponse
    {
        return $this->response(['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_' . $name, 'type' => 'function',
            'function'                                                                           => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]]]], 'tool_calls');
    }

    private function textResponse(string $text): MockResponse
    {
        return $this->response(['role' => 'assistant', 'content' => $text], 'stop');
    }

    /** @param array<string, mixed> $message */
    private function response(array $message, string $finish): MockResponse
    {
        return new MockResponse(json_encode(['id' => 'probe', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-5.6-luna',
            'choices'                             => [['index' => 0, 'finish_reason' => $finish, 'message' => $message]],
            'usage'                               => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }

    private function process(string $requestId = 'family'): void
    {
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        $this->jsonRequest('POST', $this->path . '/process', ['version' => $this->responseData()['version'], 'requestId' => $requestId], $this->headers);
        self::assertResponseIsSuccessful();
    }
}
