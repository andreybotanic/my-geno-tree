<?php

namespace App\Model;

/** Ephemeral proposal metadata, stored in the same MessageBag as its conversation. */
final class TreeMergeReview
{
    /** @param array<string, mixed>|null $proposal
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public int $version = 0,
        public int $revision = 1,
        public ?array $proposal = null,
        public ?array $result = null,
        public string $state = 'collecting',
    ) {
    }
}
