<?php

namespace App\Model;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/** One pending tool proposal, with no chat state or application receipt. */
#[Exclude]
final class TreeMergeProposal
{
    public readonly UuidV7 $id;

    /** @param array<string, mixed> $proposal */
    public function __construct(
        public readonly string $treeId,
        public int $version,
        public string $treeEtag,
        public array $proposal,
        public string $state,
        ?UuidV7 $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
    }
}
