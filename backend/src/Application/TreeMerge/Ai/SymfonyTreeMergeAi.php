<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentGrant;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SymfonyTreeMergeAi implements TreeMergeAiInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.tree_merge')] private AgentInterface $agent,
        #[Autowire(service: 'mcp.client.tree_merge.server.tree')] private ServerConnectionInterface $connection,
        private TreeMergeAgentGrant $grants,
        private RequestStack $requests,
    ) {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('MERGE_REQUEST_REQUIRED');
        $token = $this->grants->issue($draft, $attemptId);
        $request->attributes->set(MergeMcpHttpClient::class, $token);
        try {
            // The configured agent chooses and executes MCP tools; its free text has no effects.
            $this->agent->call(MergePrompt::input($draft))->getResult();
        } finally {
            $this->grants->revoke($token);
            $request->attributes->remove(MergeMcpHttpClient::class);
            try {
                $this->connection->disconnect();
            } catch (\Throwable) {
                // Finishing the attempt invalidates the scoped MCP credential first.
            }
        }
    }
}
