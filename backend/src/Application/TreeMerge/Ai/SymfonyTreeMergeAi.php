<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentGrant;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SymfonyTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(
        #[Target('ollama')] private PlatformInterface $platform,
        private TreeMergeAgentGrant $grants,
        private HttpClientInterface $http,
        #[Autowire(env: 'TREE_MERGE_MODEL')] private string $model,
        #[Autowire(env: 'TREE_MERGE_MCP_URL')] private string $mcpUrl,
        private ?MergeTokenBudget $budget = null,
    ) {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        if ('' === $this->model) {
            throw new \RuntimeException('TREE_MERGE_MODEL_MISSING');
        }
        $token = $this->grants->issue($draft, $attemptId);
        $remote = new ClientToolset('tree', Client::builder()->build(), new HttpTransport($this->mcpUrl,
            ['Authorization' => 'Merge ' . $token], new Psr18Client($this->http->withOptions(['timeout' => 15, 'max_duration' => 20]))));
        try {
            $platform = null === $this->budget ? $this->platform : new MeteredMergePlatform($this->platform, $this->budget, $draft);
            $tools = new BoundedTreeToolset($remote, $draft->tree->getId()->toRfc4122());
            $agent = new Agent($platform, $this->model, toolbox: new FaultTolerantToolbox(new McpToolbox($tools)), maxToolCalls: 16);
            // Only MCP commands can produce a proposal or a visible reply; free text is never interpreted.
            $agent->call(new MessageBag(Message::forSystem(MergePrompt::SYSTEM), Message::ofUser(MergePrompt::input($draft))),
                ['temperature' => 0, 'think' => false])->getContent();
        } finally {
            $this->grants->revoke($token);
            try {
                $remote->disconnect();
            } catch (\Throwable) {
                // A completed attempt already invalidates its scoped MCP credential.
            }
        }
    }
}
