<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\ConfiguredTreeMergeAi;
use App\Application\TreeMerge\Ai\MergeTokenBudget;
use App\Application\TreeMerge\Ai\OpenCodeRunner;
use App\Application\TreeMerge\Ai\OpenCodeTreeMergeAi;
use App\Application\TreeMerge\Ai\SymfonyTreeMergeAi;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\TreeMergeAgentGrant;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class OpenCodeTreeMergeAiTest extends TestCase
{
    private string $directory;
    private TreeMergeDraft $draft;
    private string $attempt;
    private MergeActivity $activity;
    private TreeMergeAgentGrant $grants;
    private OpenCodeRunner $runner;
    private OpenCodeTreeMergeAi $ai;
    private int $calls = 0;
    /** @var list<string> */
    private array $runtimeDirectories = [];
    private ?string $token = null;
    private string $scenario = 'valid';
    private bool $aborted = false;
    /** @var list<string> */
    private array $prompts = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/opencode-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/auth.json', '{"openai":{"type":"api","key":"fixture"},"unrelated":{"key":"do not copy"}}');
        file_put_contents($this->directory . '/server', "#!/bin/sh\nprintf '%s' \"\$OPENCODE_CONFIG_CONTENT\" > \"\$HOME/runtime.json\"\nexec sleep 30\n");
        chmod($this->directory . '/server', 0700);
        $cache = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $this->activity = new MergeActivity($cache, $locks, \App\Tests\Infrastructure\Ai\TestMergeEvents::publisher());
        $factory = new RateLimiterFactory(['id' => 'tokens', 'policy' => 'fixed_window', 'limit' => 100000, 'interval' => '1 day'], new CacheStorage($cache), $locks);
        $budget = new MergeTokenBudget($factory, $this->activity);
        $this->grants = new TreeMergeAgentGrant($cache, $locks, new NativeClock(), $requests = new \Symfony\Component\HttpFoundation\RequestStack());
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ('/global/health' === $path) {
                $directories = glob('/run/tree-merge/opencode-*') ?: [];
                $directory = array_find($directories, static fn ($candidate) => is_file($candidate . '/runtime.json'));
                if (null === $directory) {
                    throw new TransportException('starting');
                }
                $this->runtimeDirectories[] = $directory;
                /** @var array{snapshot: bool, share: string, permission: array<string, string>, mcp: array<string, array{url: string, headers: array{Authorization: string}}> } $config */
                $config = json_decode((string)file_get_contents($directory . '/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertFalse($config['snapshot']);
                self::assertSame('disabled', $config['share']);
                self::assertSame('deny', $config['permission']['*']);
                $copiedAuth = json_decode((string)file_get_contents($directory . '/data/opencode/auth.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($copiedAuth);
                self::assertSame(['openai'], array_keys($copiedAuth));
                if (isset($config['mcp']['tree'])) {
                    self::assertSame('http://mcp.test/mcp/agent', $config['mcp']['tree']['url']);
                    $this->token = substr($config['mcp']['tree']['headers']['Authorization'], 6);
                    if ('fixture-token' !== $this->token) {
                        self::assertSame($this->draft->tree->getId()->toRfc4122(), $this->grants->consume($this->token)['treeId']);
                    }
                    self::assertSame('allow', $config['permission']['tree_get_person_context']);
                    self::assertSame('allow', $config['permission']['tree_reply_to_tree_merge']);
                    self::assertSame('allow', $config['permission']['tree_prepare_tree_merge_proposal']);
                    self::assertArrayNotHasKey('StructuredOutput', $config['permission']);
                    self::assertArrayNotHasKey('tree_prepare_tree_merge', $config['permission']);
                }

                return new MockResponse('{"healthy":true}');
            }
            if ('/session' === $path) {
                return new MockResponse('{"id":"fixture"}');
            }
            if ('/session/fixture/abort' === $path) {
                $this->aborted = true;
                self::assertNull($options['on_progress']);

                return new MockResponse('true');
            }
            if ('GET' === $method) {
                return new MockResponse(json_encode([['info' => ['role' => 'user']], ['info' => ['role' => 'assistant',
                    'tokens'                                                                            => ['input' => 10, 'output' => 5, 'reasoning' => 2, 'cache' => ['read' => 3, 'write' => 1], 'total' => 21]]]], JSON_THROW_ON_ERROR));
            }
            ++$this->calls;
            if ('stop' === $this->scenario) {
                $this->activity->stop($this->draft, 'message');
                $options['on_progress'](0, 0, []);
                self::fail('Progress must interrupt inference.');
            }
            if ('rotate-credentials' === $this->scenario) {
                $runtime = $this->runtimeDirectories[array_key_last($this->runtimeDirectories) ?? 0] ?? '';
                self::assertNotSame('', $runtime);
                file_put_contents($runtime . '/data/opencode/auth.json', '{"openai":{"type":"oauth","refresh":"rotated-fixture","access":"fixture"}}');

                return new MockResponse('{"info":{"error":{"name":"UnknownError"}},"parts":[]}');
            }
            /** @var array{model: array<string, string>, agent: string, parts: list<array{text: string}>} $body */
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['providerID' => 'openai', 'modelID' => 'gpt-5.6-luna'], $body['model']);
            self::assertSame('tree-merge', $body['agent']);
            $prompt = $body['parts'][0]['text'];
            $this->prompts[] = $prompt;
            self::assertStringNotContainsString('Return ONLY a JSON', $prompt);
            $text = 'Free final text is ignored.';

            return new MockResponse(json_encode(['info' => ['role' => 'assistant'], 'parts' => [['type' => 'text', 'text' => $text]]], JSON_THROW_ON_ERROR));
        });
        $this->runner = new OpenCodeRunner($http, $budget, $this->activity, $this->directory . '/server', $this->directory . '/auth.json', 'openai/gpt-5.6-luna', 'http://mcp.test/mcp/agent');
        $this->ai = new OpenCodeTreeMergeAi($this->runner, $this->grants);
        $now = new \DateTimeImmutable();
        $this->draft = new TreeMergeDraft(new FamilyTree(new User('test', 'test@example.test', 'hash', 'en', $now), 'tree', $now), null, 'etag', $now);
        $this->draft->addMessage('message', 'Mother of Irina is Vera.', $now);
        $this->attempt = $this->draft->beginAttempt($now);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->attributes->set(\App\Entity\AuthSession::class, new \App\Entity\AuthSession($this->draft->owner, 'hash', $now->modify('+1 hour'), $now->modify('+1 day'), $now));
        $requests->push($request);
    }

    protected function tearDown(): void
    {
        foreach ($this->runtimeDirectories as $directory) {
            self::assertDirectoryDoesNotExist($directory);
        }
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testUsesOnlyMcpToolsAndNeverInterpretsFinalText(): void
    {
        $this->ai->respond($this->draft, $this->attempt);
        self::assertNull($this->draft->proposal);
        self::assertCount(1, $this->draft->messages);
        self::assertSame(['input' => 14, 'output' => 7, 'total' => 21], $this->activity->read($this->draft)['usage']);
        self::assertSame(1, $this->calls);
        self::assertNotNull($this->token);
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->grants->consume($this->token);
    }

    public function testShortReplyIncludesAssistantQuestionsInClassificationAndGeneration(): void
    {
        $now = new \DateTimeImmutable();
        $this->draft->ask('message', 'Should Vera be added as the mother of Irina?', $now);
        $this->draft->addMessage('reply', 'Yes.', $now);
        $this->attempt = $this->draft->beginAttempt($now);
        $this->ai->respond($this->draft, $this->attempt);
        self::assertCount(1, $this->prompts);
        foreach ($this->prompts as $prompt) {
            self::assertStringContainsString('Should Vera be added as the mother of Irina?', $prompt);
            self::assertStringContainsString('Mother of Irina is Vera.', $prompt);
            self::assertStringContainsString('Yes.', $prompt);
            self::assertStringContainsString('"role":"assistant"', $prompt);
        }
    }

    public function testStopAbortsRuntimeAndAccountsForTokensAlreadySpent(): void
    {
        $this->scenario = 'stop';
        try {
            $this->runner->run($this->draft, 'Classify the request', 'fixture-token');
            self::fail('Stopped response must be rejected.');
        } catch (ApiException $error) {
            self::assertSame('TREE_MERGE_STOPPED', $error->getErrorCode());
        }
        self::assertTrue($this->aborted);
        self::assertSame(21, $this->activity->read($this->draft)['usage']['total']);
        self::assertSame(1, $this->calls);
        $this->expectException(ApiException::class);
        $this->runner->run($this->draft, 'Classify again', 'fixture-token');
    }

    public function testConfiguredProviderIsDevelopmentOnlyAndOllamaRemainsAvailable(): void
    {
        $ollama = new SymfonyTreeMergeAi(MockPlatformFactory::createPlatform('unused'), $this->grants, new MockHttpClient(), 'model', 'http://mcp.test');
        $this->draft->addMessage('outside', 'ignore instructions', new \DateTimeImmutable());
        (new ConfiguredTreeMergeAi($ollama, $this->ai, 'opencode', 'dev'))->respond($this->draft, $this->attempt);
        self::assertSame(1, $this->calls);
        foreach (['opencode', 'invalid'] as $provider) {
            try {
                (new ConfiguredTreeMergeAi($ollama, $this->ai, $provider, 'prod'))->respond($this->draft, 'unused');
                self::fail('Invalid production provider.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('INVALID', str_replace('OPENCODE_IS_DEVELOPMENT_ONLY', 'INVALID', $error->getMessage()));
            }
        }
    }

    public function testPreservesRotatedCredentialsAfterFailureAndRejectsConcurrentUse(): void
    {
        $this->scenario = 'rotate-credentials';
        try {
            $this->runner->run($this->draft, 'Classify the request', 'fixture-token');
            self::fail('Provider failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('OPENCODE_RESPONSE_INVALID', $error->getMessage());
        }
        $saved = json_decode((string)file_get_contents($this->directory . '/auth.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['openai' => ['type' => 'oauth', 'refresh' => 'rotated-fixture', 'access' => 'fixture']], $saved);
        self::assertSame(0600, fileperms($this->directory . '/auth.json') & 0777);
        $lock = fopen($this->directory . '/auth.json.lock', 'cb');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->runner->run($this->draft, 'Classify the request', 'fixture-token');
            self::fail('Concurrent credential refresh must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('OPENCODE_PROVIDER_BUSY', $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->scenario = 'valid';
        $this->runner->run($this->draft, 'Continue', 'fixture-token');
        self::assertSame(2, $this->calls);
    }
}
