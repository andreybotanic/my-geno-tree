<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Application\TreeMerge\Ai\SymfonyTreeMergeAi;
use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\TreeMergeAgentTokenHandler;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SymfonyTreeMergeAiTest extends KernelTestCase
{
    public function testStockHttpBridgeSendsHistoryModelAndChatContextAndIgnoresFreeText(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $draft = $this->draft();
        $calls = 0;
        $tokenHandler = $container->get(TreeMergeAgentTokenHandler::class);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($draft, $tokenHandler, &$calls): MockResponse {
            ++$calls;
            self::assertSame('POST', $method);
            self::assertSame('http://hermes:8642/v1/chat/completions', $url);
            self::assertIsString($_ENV['TREE_MERGE_AGENT_KEY']);
            self::assertContains('Authorization: Bearer ' . $_ENV['TREE_MERGE_AGENT_KEY'], $options['headers']);
            /** @var array{model: string, messages: list<array{content: string}>} $body */
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('gpt-5.6-luna', $body['model']);
            self::assertArrayNotHasKey('tools', $body);
            self::assertArrayNotHasKey('mcp_servers', $body);
            self::assertStringContainsString('reply_to_tree_merge', $body['messages'][0]['content']);
            /** @var array{accessToken: string, attemptId: string, treeId: string, messages: array<mixed>} $input */
            $input = json_decode($body['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('attempt', $input['attemptId']);
            self::assertSame($draft->tree->getId()->toRfc4122(), $input['treeId']);
            self::assertSame($draft->messages, $input['messages']);
            self::assertSame($tokenHandler->issue($draft), $input['accessToken']);

            return new MockResponse(json_encode(['id' => 'http-agent', 'object' => 'chat.completion', 'created' => 1,
                'model'                               => 'gpt-5.6-luna', 'choices' => [['index' => 0, 'finish_reason' => 'stop',
                    'message'                                                                   => ['role' => 'assistant', 'content' => '{"action":"proposal","reply":"Never display this."}']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110]], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type: application/json']]);
        });
        $container->set('merge_ai.client', $http);
        $container->get(SymfonyTreeMergeAi::class)->respond($draft, 'attempt');
        self::assertSame(1, $calls);
        self::assertNull($draft->proposal);
        self::assertCount(3, $draft->messages);
    }

    public function testAgentFailurePropagatesWithoutRevokingTheChatCredential(): void
    {
        self::bootKernel();
        $draft = $this->draft();
        $tokenHandler = self::getContainer()->get(TreeMergeAgentTokenHandler::class);
        $token = $tokenHandler->issue($draft);
        $execution = $this->createMock(ExecutionInterface::class);
        $execution->expects($this->once())->method('getResult')->willThrowException(new \RuntimeException('provider unavailable'));
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects($this->once())->method('call')->willReturn($execution);
        try {
            (new SymfonyTreeMergeAi($agent, $tokenHandler))->respond($draft, 'attempt');
            self::fail('Provider failure was swallowed');
        } catch (\RuntimeException $error) {
            self::assertSame('provider unavailable', $error->getMessage());
        }
        self::assertSame($token, $tokenHandler->issue($draft));
    }

    private function draft(): TreeMergeDraft
    {
        $now = new \DateTimeImmutable();
        $owner = new User('test', 'test@example.test', 'hash', 'en', $now);
        $draft = new TreeMergeDraft(new FamilyTree($owner, 'Family', $now), null, 'etag', $now);
        $draft->addMessage('request', 'Vera is my mother.', $now, false);
        $draft->ask('request', 'Is Vera a new person?', $now);
        $draft->addMessage('answer', 'Yes; apply the changes.', $now, false);
        $request = new Request();
        $request->attributes->set(AuthSession::class, new AuthSession($owner, 'hash', $now->modify('+1 hour'), $now->modify('+1 day'), $now));
        self::getContainer()->get(RequestStack::class)->push($request);

        return $draft;
    }
}
