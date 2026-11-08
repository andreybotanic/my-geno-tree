<?php

namespace App\Security;

use App\Model\Conversation;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ChatExecution
{
    public int $version;
    public ?ExecutionInterface $native = null;
    public string $commentaryBuffer = '';
    public float $lastCommentaryPublishedAt = 0;
    /** @var list<array<string, mixed>> */
    public array $observations = [];

    public function __construct(public readonly Conversation $conversation, public readonly string $attemptId)
    {
        $this->version = $conversation->version;
    }
}
