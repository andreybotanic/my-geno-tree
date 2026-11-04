<?php

// Seed only the selected development provider; preserve subsequent OAuth rotations.
// A fresh WSL login replaces the seed on the next backend recreation.
$provider = explode('/', getenv('TREE_MERGE_OPENCODE_MODEL') ?: 'openai/gpt-5.6-luna', 2)[0];
$credentials = json_decode(file_get_contents('/run/secrets/opencode-auth.json'), true, flags: JSON_THROW_ON_ERROR);
if (!isset($credentials[$provider])) {
    throw new RuntimeException('The selected OpenCode provider is not authenticated.');
}
$destination = getenv('TREE_MERGE_OPENCODE_AUTH') ?: '/var/lib/tree-merge-opencode/auth.json';
$directory = dirname($destination);
if (!is_dir($directory)) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
    }
}
chown($directory, 'www-data');
chgrp($directory, 'www-data');
$seed = json_encode([$provider => $credentials[$provider]], JSON_THROW_ON_ERROR);
$fingerprint = hash('sha256', $seed);
$stamp = $destination . '.seed';
if (!is_file($destination) || !is_file($stamp) || trim(file_get_contents($stamp)) !== $fingerprint) {
    file_put_contents($destination, $seed);
    file_put_contents($stamp, $fingerprint);
    chmod($stamp, 0600);
}
chmod($destination, 0600);
chown($destination, 'www-data');
chgrp($destination, 'www-data');
