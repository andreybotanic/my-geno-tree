<?php

namespace App\Application\TreeMerge\Ai;

use App\Application\TreeMerge\MergeActivity;
use App\Entity\TreeMergeDraft;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A separate disposable OpenCode runtime per call prevents sharing MCP credentials or conversation history. */
final readonly class OpenCodeRunner
{
    public function __construct(private HttpClientInterface $http,
        private MergeTokenBudget $budget,
        private MergeActivity $activity,
        #[Autowire(env: 'TREE_MERGE_OPENCODE_BINARY')] private string $binary,
        #[Autowire(env: 'TREE_MERGE_OPENCODE_AUTH')] private string $authFile,
        #[Autowire(env: 'TREE_MERGE_OPENCODE_MODEL')] private string $model,
        #[Autowire(env: 'TREE_MERGE_MCP_URL')] private string $mcpUrl)
    {
    }

    public function run(TreeMergeDraft $draft, string $prompt, string $mcpToken): void
    {
        $this->budget->assertAvailable($draft);
        $this->activity->assertRunning($draft);
        [$provider, $model] = explode('/', $this->model, 2);
        $directory = '/run/tree-merge/opencode-' . bin2hex(random_bytes(12));
        $files = new Filesystem();
        $process = null;
        $credentialLock = fopen($this->authFile . '.lock', 'cb');
        if (false === $credentialLock) {
            throw new \RuntimeException('OPENCODE_CREDENTIAL_LOCK_UNAVAILABLE');
        }
        if (!flock($credentialLock, LOCK_EX | LOCK_NB)) {
            fclose($credentialLock);
            throw new \RuntimeException('OPENCODE_PROVIDER_BUSY');
        }
        try {
            $files->mkdir($directory . '/data/opencode', 0700);
            $credentials = json_decode((string)file_get_contents($this->authFile), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($credentials) || !isset($credentials[$provider])) {
                throw new \RuntimeException('OPENCODE_PROVIDER_NOT_AUTHENTICATED');
            }
            $files->dumpFile($directory . '/data/opencode/auth.json', json_encode([$provider => $credentials[$provider]], JSON_THROW_ON_ERROR));
            $files->chmod($directory . '/data/opencode/auth.json', 0600);
            $permission = ['*' => 'deny'];
            foreach (BoundedTreeToolset::TOOLS as $tool) {
                $permission['tree_' . $tool] = 'allow';
            }
            $config = ['snapshot' => false, 'share' => 'disabled', 'autoupdate' => false, 'enabled_providers' => [$provider],
                'permission'      => $permission, 'agent' => ['tree-merge' => ['mode' => 'primary', 'prompt' => MergePrompt::SYSTEM,
                    'steps'                                                           => 16, 'permission' => $permission]],
                'mcp' => ['tree' => ['type' => 'remote', 'url' => $this->mcpUrl,
                    'oauth'                 => false, 'headers' => ['Authorization' => 'Merge ' . $mcpToken], 'timeout' => 20000]]];
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            if (false === $socket) {
                throw new \RuntimeException('OPENCODE_PORT_UNAVAILABLE');
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            if (false === $address) {
                throw new \RuntimeException('OPENCODE_PORT_UNAVAILABLE');
            }
            $port = substr($address, strrpos($address, ':') + 1);
            $password = bin2hex(random_bytes(24));
            $environment = ['HOME'                => $directory, 'XDG_CONFIG_HOME' => $directory . '/config', 'XDG_DATA_HOME' => $directory . '/data',
                'XDG_CACHE_HOME'                  => $directory . '/cache', 'XDG_STATE_HOME' => $directory . '/state',
                'OPENCODE_DISABLE_PROJECT_CONFIG' => '1', 'OPENCODE_CONFIG_CONTENT' => json_encode($config, JSON_THROW_ON_ERROR),
                'OPENCODE_SERVER_PASSWORD'        => $password, 'OPENCODE_SERVER_USERNAME' => 'tree-merge'];
            $process = new Process([$this->binary, 'serve', '--hostname', '127.0.0.1', '--port', $port], $directory, $environment);
            $process->disableOutput();
            $process->start();
            $client = $this->http->withOptions(['base_uri' => 'http://' . $address, 'auth_basic' => ['tree-merge', $password],
                'timeout'                                  => 270, 'max_duration' => 270, 'on_progress' => fn () => $this->activity->assertRunning($draft)]);
            $ready = false;
            for ($try = 0; $try < 100 && $process->isRunning(); ++$try) {
                $this->activity->assertRunning($draft);
                try {
                    $ready = true === ($client->request('GET', '/global/health', ['timeout' => 0.2, 'max_duration' => 0.2])->toArray()['healthy'] ?? false);
                } catch (\Throwable) {
                    usleep(100000);
                }
                if ($ready) {
                    break;
                }
            }
            if (!$ready) {
                throw new \RuntimeException('OPENCODE_START_FAILED');
            }
            $session = $client->request('POST', '/session', ['json' => ['title' => 'Tree merge']])->toArray();
            if (!is_string($session['id'] ?? null)) {
                throw new \RuntimeException('OPENCODE_SESSION_INVALID');
            }
            $path = '/session/' . rawurlencode($session['id']) . '/message';
            try {
                $result = $client->request('POST', $path, ['json' => [
                    'model' => ['providerID' => $provider, 'modelID' => $model],
                    'agent' => 'tree-merge', 'system' => MergePrompt::SYSTEM,
                    'parts' => [['type' => 'text', 'text' => $prompt]],
                ]])->toArray();
                if (!is_array($result['info'] ?? null) || isset($result['info']['error'])) {
                    throw new \RuntimeException('OPENCODE_RESPONSE_INVALID');
                }
                // Final free text is deliberately ignored; MCP commands own every effect.
            } finally {
                // Each assistant step has its own usage; final-message usage alone omits tool rounds.
                $client = $client->withOptions(['on_progress' => null, 'timeout' => 5, 'max_duration' => 5]);
                if ($this->activity->isStopped($draft)) {
                    $client->request('POST', '/session/' . rawurlencode($session['id']) . '/abort')->getContent();
                }
                $messages = $client->request('GET', $path)->toArray();
                foreach ($messages as $message) {
                    $info = is_array($message) && is_array($message['info'] ?? null) ? $message['info'] : [];
                    if ('assistant' !== ($info['role'] ?? null) || !is_array($info['tokens'] ?? null)) {
                        continue;
                    }
                    /** @var array{input?: int, output?: int, reasoning?: int, total?: int, cache?: array{read?: int, write?: int}} $tokens */
                    $tokens = $info['tokens'];
                    $cache = is_array($tokens['cache'] ?? null) ? $tokens['cache'] : [];
                    $input = (int)($tokens['input'] ?? 0) + (int)($cache['read'] ?? 0) + (int)($cache['write'] ?? 0);
                    $output = (int)($tokens['output'] ?? 0) + (int)($tokens['reasoning'] ?? 0);
                    $this->budget->record($draft, $input, $output, (int)($tokens['total'] ?? 0));
                }
            }
        } finally {
            $process?->stop(0.5);
            try {
                // OAuth refresh tokens may rotate. Preserve the selected provider's updated
                // credentials before removing its disposable runtime, even after inference fails.
                $runtimeAuth = $directory . '/data/opencode/auth.json';
                if (is_file($runtimeAuth)) {
                    $refreshed = json_decode((string)file_get_contents($runtimeAuth), true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($refreshed) && is_array($refreshed[$provider] ?? null)) {
                        $files->dumpFile($this->authFile, json_encode([$provider => $refreshed[$provider]], JSON_THROW_ON_ERROR));
                        $files->chmod($this->authFile, 0600);
                    }
                }
            } finally {
                $files->remove($directory);
                flock($credentialLock, LOCK_UN);
                fclose($credentialLock);
            }
        }
    }
}
