<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Application\TreeMerge\Ai\MergeMcpHttpClient;
use App\Application\TreeMerge\Ai\SymfonyTreeMergeAi;
use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\TreeMergeAgentGrant;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class SymfonyTreeMergeAiTest extends KernelTestCase
{
    public function testAnyInjectedAgentIsUsedAndFailureRevokesItsCredentials(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $requests = $container->get(RequestStack::class);
        $now = new \DateTimeImmutable();
        $owner = new User('test', 'test@example.test', 'hash', 'en', $now);
        $draft = new TreeMergeDraft(new FamilyTree($owner, 'Family', $now), null, 'etag', $now);
        $request = new Request();
        $request->attributes->set(AuthSession::class, new AuthSession($owner, 'hash', $now->modify('+1 hour'), $now->modify('+1 day'), $now));
        $requests->push($request);
        $token = null;
        $execution = $this->createMock(ExecutionInterface::class);
        $execution->expects($this->once())->method('getResult')->willThrowException(new \RuntimeException('provider unavailable'));
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects($this->once())->method('call')->willReturnCallback(static function (string $input, array $options = []) use ($request, &$token, $execution): ExecutionInterface {
            self::assertJson($input);
            self::assertSame([], $options);
            $token = $request->attributes->get(MergeMcpHttpClient::class);
            self::assertIsString($token);

            return $execution;
        });
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->expects($this->once())->method('disconnect')->willThrowException(new \RuntimeException('already expired'));
        $grants = $container->get(TreeMergeAgentGrant::class);
        try {
            (new SymfonyTreeMergeAi($agent, $connection, $grants, $requests))->respond($draft, 'attempt');
            self::fail('Provider failure was swallowed');
        } catch (\RuntimeException $error) {
            self::assertSame('provider unavailable', $error->getMessage());
        }
        self::assertFalse($request->attributes->has(MergeMcpHttpClient::class));
        self::assertIsString($token);
        $this->expectException(AuthenticationException::class);
        $grants->consume($token);
    }

    public function testConfiguredOllamaModelKeepsOptionsOutOfTheModelName(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        /** @var AgentInterface $agent Debug is disabled, so the profiler decorator is absent. */
        $agent = $container->get('ai.agent.tree_merge');
        self::assertInstanceOf(\Symfony\AI\Agent\Agent::class, $agent);
        $name = $agent->getModel();
        if ('' === $name) {
            throw new \LogicException('The configured model must not be empty.');
        }
        $model = $container->get('ai.platform.ollama')->getModelCatalog()->getModel($name);
        self::assertSame($_ENV['TREE_MERGE_MODEL'], $model->getName());
        self::assertSame(0.7, $model->getOptions()['temperature']);
        self::assertIsBool($model->getOptions()['think']);
    }

    public function testAgentChoosesMcpCallsRepairsErrorsAndFinalTextHasNoEffects(): void
    {
        $clock = new MockClock();
        $now = $clock->now();
        $owner = new User('test', 'test@example.test', 'hash', 'en', $now);
        $draft = new TreeMergeDraft(new FamilyTree($owner, 'Family', $now), null, 'etag', $now);
        $draft->addMessage('request', 'Vera is my mother.', $now, false);
        $draft->ask('request', 'Is Vera a new person?', $now);
        $draft->addMessage('answer', 'Yes; apply the changes.', $now, false);
        self::bootKernel();
        $container = self::getContainer();
        $requests = $container->get(RequestStack::class);
        $request = new Request();
        $request->attributes->set(AuthSession::class, new AuthSession($owner, 'hash', $now->modify('+1 hour'), $now->modify('+1 day'), $now));
        $requests->push($request);
        $grants = $container->get(TreeMergeAgentGrant::class);
        $token = null;
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls, &$token, $grants): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://proxy:8082/mcp/agent', $url);
            foreach ($options['headers'] as $header) {
                if (str_starts_with($header, 'Authorization: Merge ')) {
                    $token = substr($header, strlen('Authorization: Merge '));
                    self::assertSame('attempt', $grants->consume($token)['attemptId']);
                }
            }
            /** @var array{id: int, method: string, params: array{name: string}} $body */
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $result = match ($body['method']) {
                'server/discover' => ['supportedVersions' => ['2026-07-28'], 'serverInfo' => ['name' => 'tree', 'version' => '1'], 'capabilities' => ['tools' => new \stdClass()]],
                'tools/list'      => ['tools' => array_map(static fn ($name) => ['name' => $name, 'inputSchema' => [
                    'type' => 'object', 'properties' => ['treeId' => ['type' => 'string'], 'reply' => ['type' => 'string']], 'required' => ['treeId'],
                ]], ['get_tree_context', 'reply_to_tree_merge'])],
                'tools/call' => ['content' => [['type' => 'text', 'text' => [] === $calls ? '{"code":"TRY_AGAIN"}' : '{"state":"collecting"}']], 'isError' => [] === $calls],
                default      => throw new \LogicException($body['method']),
            };
            if ('tools/call' === $body['method']) {
                $calls[] = $body['params']['name'];
            }

            return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $result], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });
        $round = 0;
        $treeId = $draft->tree->getId()->toRfc4122();
        $platform = MockPlatformFactory::createPlatform(static function (Model $model, mixed $payload) use (&$round, $treeId) {
            $text = json_encode($payload, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('Vera is my mother.', $text);
            self::assertStringContainsString('Is Vera a new person?', $text);
            self::assertStringContainsString('Yes; apply the changes.', $text);
            if ($round > 0) {
                self::assertStringContainsString('TRY_AGAIN', $text);
            }

            return match (++$round) {
                1       => new ToolCallResult([new ToolCall('read', 'tree_get_tree_context', ['treeId' => $treeId])]),
                2       => new ToolCallResult([new ToolCall('reply', 'tree_reply_to_tree_merge', ['treeId' => $treeId, 'reply' => 'Use the confirmation button.'])]),
                3       => new TextResult('{"action":"proposal","reply":"This must not be displayed."}'),
                default => throw new \LogicException('Unexpected model invocation'),
            };
        });
        $container->set('ai.platform.ollama', $platform);
        $container->set(MergeMcpHttpClient::class, new MergeMcpHttpClient(new Psr18Client($http), $requests, 'http://proxy:8082/mcp/agent'));
        $container->get(SymfonyTreeMergeAi::class)->respond($draft, 'attempt');
        self::assertSame(['get_tree_context', 'reply_to_tree_merge'], $calls);
        self::assertNull($draft->proposal);
        self::assertCount(3, $draft->messages);
        self::assertNotNull($token);
        $this->expectException(AuthenticationException::class);
        $grants->consume($token);
    }
}
