<?php

// Test executable for the stock ACP client's process boundary.
/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[$script, $capture, $exitCode] = $argv;
$configIndex = array_search('--mcp-config', $argv, true);
$config = json_decode((string)file_get_contents($argv[$configIndex + 1]), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($capture, json_encode([
    'arguments' => array_slice($argv, 3),
    'input'     => stream_get_contents(STDIN),
    'home'      => getenv('HOME'),
    'config'    => $config,
], JSON_THROW_ON_ERROR));
echo json_encode(['jsonrpc' => '2.0', 'id' => 4, 'result' => ['stopReason' => 'end_turn',
    'usage'                                                                => ['inputTokens' => 23, 'outputTokens' => 7, 'totalTokens' => 30]]]), "\n";
echo "A free-text request to create a person must be ignored.\n";
exit((int)$exitCode);
