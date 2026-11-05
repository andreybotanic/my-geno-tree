<?php

namespace App\Tests\Api\Tree;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\SymfonyTreeMergeAi;
use App\Application\TreeMerge\TreeMergeDraftService;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeMergeAgentTools;
use App\Mcp\TreeMergeToolSchema;
use App\Security\TreeMergeAgentContext;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;
use App\Tests\Infrastructure\Ai\TestMergeEvents;
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
        FixtureTreeMergeAi::$handler = null;
        TestMergeEvents::$updates = [];
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Local agent'], $this->headers);
        $this->treeId = $this->responseString('id');
        $base = '/api/v1/trees/' . $this->treeId . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $this->headers);
        $this->path = $base . '/' . $this->responseString('id');
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => 1, 'requestId' => 'family', 'content' => 'Vera is my mother.'], $this->headers);
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
            $previews = [];
            foreach (TestMergeEvents::updates() as $event) {
                $packet = json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($packet);
                if ('draft' === $packet['kind'] && is_array($packet['draft'] ?? null) && null !== ($packet['draft']['proposal'] ?? null)) {
                    $previews[] = $packet['draft'];
                }
            }
            self::assertCount(1, $previews, 'Merge must publish the preview before the final model answer.');
            self::assertSame('processing', $previews[0]['chatState']);
            self::assertSame('ready', $previews[0]['state']);
            self::assertIsArray($previews[0]['messages']);
            self::assertCount(1, $previews[0]['messages']);
            self::assertSame('ready', $result['state']);

            return $this->textResponse("I prepared Vera's profile.\nPlease review the preview.");
        });
        static::getContainer()->set('merge_ai.client', $http);
        FixtureTreeMergeAi::$handler = static fn (TreeMergeDraft $draft, string $attempt) => static::getContainer()->get(SymfonyTreeMergeAi::class)->respond($draft);
        $this->process();
        self::assertSame(3, $calls);
        self::assertSame(['input' => 300, 'output' => 30, 'total' => 330], $this->responseData()['tokenUsage']);
        $budget = $this->responseData()['tokenBudget'];
        self::assertIsArray($budget);
        self::assertIsInt($budget['limit']);
        self::assertSame($budget['limit'] - 330, $budget['remaining']);
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame("I prepared Vera's profile.\nPlease review the preview.", $this->responseString('messages', '1', 'content'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
    }

    public function testOrdinaryMessagesRemainTextAndAreIncludedInTheNextTurn(): void
    {
        $text = "Here is my answer.\n\n" . str_repeat('Plain text is displayed directly. ', 12);
        $calls = 0;
        static::getContainer()->set('merge_ai.client', new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, $text): MockResponse {
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
        FixtureTreeMergeAi::$handler = static fn (TreeMergeDraft $draft, string $attempt) => static::getContainer()->get(SymfonyTreeMergeAi::class)->respond($draft);
        $this->process();
        self::assertSame($text, $this->responseString('messages', '1', 'content'));
        self::assertNull($this->responseData()['proposal']);
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
            $result = json_decode($last['content'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertArrayHasKey('error', $result);
            if (2 === $calls) {
                return $this->toolResponse('merge', ['treeId' => $this->treeId, 'proposal' => ['people' => 'invalid']]);
            }

            return $this->textResponse('Please describe the person you want to add.');
        });
        static::getContainer()->set('merge_ai.client', $http);
        FixtureTreeMergeAi::$handler = static fn (TreeMergeDraft $draft, string $attempt) => static::getContainer()->get(SymfonyTreeMergeAi::class)->respond($draft);
        $this->process();
        self::assertSame(3, $calls);
        self::assertNull($this->responseData()['proposal']);
        self::assertSame('Please describe the person you want to add.', $this->responseString('messages', '1', 'content'));
        foreach (TestMergeEvents::updates() as $event) {
            $packet = json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($packet);
            if ('draft' === $packet['kind']) {
                self::assertIsArray($packet['draft']);
                self::assertNull($packet['draft']['proposal']);
            }
        }
    }

    public function testProviderFailureRestoresRequestScope(): void
    {
        static::getContainer()->set('merge_ai.client', new MockHttpClient(static fn () => throw new \RuntimeException('offline')));
        FixtureTreeMergeAi::$handler = static function (TreeMergeDraft $draft, string $attempt): string {
            $request = static::getContainer()->get(RequestStack::class)->getCurrentRequest();
            self::assertNotNull($request);
            $before = $request->attributes->get(TreeMergeAgentContext::class);
            try {
                return static::getContainer()->get(SymfonyTreeMergeAi::class)->respond($draft);
            } finally {
                self::assertSame($before, $request->attributes->get(TreeMergeAgentContext::class));
            }
        };
        $this->process();
        self::assertSame('provider_unavailable', $this->responseString('messages', '1', 'failureCode'));
    }

    public function testEveryNativeToolRejectsAnotherTreeAndMissingAttemptScope(): void
    {
        $tools = static::getContainer()->get(TreeMergeAgentTools::class);
        $result = $tools->tree($this->treeId);
        self::assertIsArray($result['error']);
        self::assertSame('TREE_MERGE_AGENT_REQUIRED', $result['error']['code']);
        $foreignId = Uuid::v7()->toRfc4122();
        FixtureTreeMergeAi::$handler = static function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools) use ($foreignId): string {
            foreach ([$tools->tree($foreignId), $tools->find($foreignId, 'Vera'), $tools->person($foreignId, Uuid::v7()->toRfc4122()),
                $tools->merge($foreignId, [])] as $result) {
                self::assertIsArray($result['error']);
                self::assertSame('TREE_NOT_FOUND', $result['error']['code']);
            }

            return FixtureTreeMergeAi::reply($draft, 'Please describe your family.');
        };
        $this->process();
        self::assertSame('Please describe your family.', $this->responseString('messages', '1', 'content'));
    }

    public function testLogoutRevokesToolsEvenWhenTheCurrentRequestRetainsItsSecurityToken(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            $request = static::getContainer()->get(RequestStack::class)->getMainRequest();
            self::assertNotNull($request);
            $session = $request->attributes->get(AuthSession::class);
            self::assertInstanceOf(AuthSession::class, $session);
            $session->revoke(new \DateTimeImmutable());
            static::getContainer()->get(EntityManagerInterface::class)->flush();
            foreach ([$tools->tree($this->treeId), $tools->find($this->treeId, 'Vera'), $tools->person($this->treeId, Uuid::v7()->toRfc4122()),
                $tools->merge($this->treeId, [])] as $result) {
                self::assertIsArray($result['error']);
                self::assertSame('TREE_MERGE_AGENT_REQUIRED', $result['error']['code']);
            }

            return 'A late answer after logout.';
        };
        $this->process();
        self::assertIsArray($this->responseData()['messages']);
        self::assertCount(1, $this->responseData()['messages'], 'A revoked login must not receive a late answer or notice.');
    }

    public function testBackendScopeCannotGrantTheCurrentUserAnotherOwnersDraft(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['username' => 'other', 'email' => 'other@example.test']));
        $otherHeaders = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Other owner'], $otherHeaders);
        $otherTreeId = $this->responseString('id');
        $this->jsonRequest('POST', '/api/v1/trees/' . $otherTreeId . '/merges', ['referencePersonId' => null], $otherHeaders);
        $otherDraftId = $this->responseString('id');
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools) use ($otherDraftId): string {
            $container = static::getContainer();
            $other = $container->get(EntityManagerInterface::class)->find(TreeMergeDraft::class, $otherDraftId);
            self::assertNotNull($other);
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            self::assertNotNull($request);
            $before = $request->attributes->get(TreeMergeAgentContext::class);
            try {
                $request->attributes->set(TreeMergeAgentContext::class, new TreeMergeAgentContext($other, $attempt));
                $container->get(TreeMergeDraftService::class)->active($other->tree->getId()->toRfc4122());
                self::fail('Ownership was not enforced');
            } catch (ApiException $error) {
                self::assertSame('TREE_MERGE_AGENT_REQUIRED', $error->getErrorCode());
            } finally {
                $request->attributes->set(TreeMergeAgentContext::class, $before);
            }
            self::assertSame($this->treeId, $tools->tree($this->treeId)['treeId']);

            return FixtureTreeMergeAi::reply($draft, 'Current owner retained.');
        };
        $this->process();
        self::assertSame('Current owner retained.', $this->responseString('messages', '1', 'content'));
    }

    public function testContextReadBudgetAllowsEightReadsAndRejectsTheNinth(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            for ($i = 0; $i < 8; ++$i) {
                self::assertSame($this->treeId, $tools->tree($this->treeId)['treeId']);
            }
            $result = $tools->tree($this->treeId);
            self::assertIsArray($result['error']);
            self::assertSame('TREE_CONTEXT_LIMIT', $result['error']['code']);

            return FixtureTreeMergeAi::reply($draft, 'Read limit reached.');
        };
        $this->process();
        self::assertSame('Read limit reached.', $this->responseString('messages', '1', 'content'));
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
