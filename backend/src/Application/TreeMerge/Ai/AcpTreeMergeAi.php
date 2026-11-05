<?php

namespace App\Application\TreeMerge\Ai;

use App\Entity\TreeMergeDraft;
use App\Security\TreeMergeAgentGrant;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Supplies a message-scoped MCP connection to the stock ACP client. */
#[Exclude]
final readonly class AcpTreeMergeAi implements TreeMergeAiInterface
{
    /** @param non-empty-list<string> $client */
    public function __construct(
        private TreeMergeAgentGrant $grants,
        private MergeTokenBudget $budget,
        private Filesystem $files,
        private array $client,
        private string $agent,
        private string $model,
        private string $mcpUrl,
    ) {
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        $this->budget->assertAvailable($draft);
        $home = '/run/tree-merge/acp-' . bin2hex(random_bytes(16));
        $token = $this->grants->issue($draft, $attemptId);
        try {
            $this->files->mkdir($home, 0700);
            $config = $home . '/mcp.json';
            $this->files->dumpFile($config, json_encode(['mcpServers' => [[
                'type'    => 'http', 'name' => 'tree', 'url' => $this->mcpUrl,
                'headers' => [['name' => 'Authorization', 'value' => 'Merge ' . $token]],
            ]]], JSON_THROW_ON_ERROR));
            $process = new Process([...$this->client, '--agent', $this->agent, '--model', $this->model,
                '--cwd', '/', '--mcp-config', $config, '--no-fs', '--no-terminal', '--deny-all',
                '--format', 'json', '--json-strict', '--timeout', '235', 'exec', '--file', '-'],
                $home, ['HOME' => $home], MergePrompt::SYSTEM . "\n\n" . MergePrompt::input($draft), 245);
            $process->run();
            // Read ACP accounting only. The user-visible answer was delivered by the agent's MCP command.
            foreach (explode("\n", $process->getOutput()) as $line) {
                $event = json_decode($line, true);
                if (!is_array($event) || !is_array($result = $event['result'] ?? null)
                    || !is_array($usage = $result['usage'] ?? null)) {
                    continue;
                }
                if (is_int($usage['inputTokens'] ?? null) && is_int($usage['outputTokens'] ?? null)) {
                    $this->budget->record($draft, $usage['inputTokens'], $usage['outputTokens'],
                        is_int($usage['totalTokens'] ?? null) ? $usage['totalTokens'] : null);
                }
            }
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('ACP session failed.');
            }
        } finally {
            $this->grants->revoke($token);
            $this->files->remove($home);
        }
    }
}
