<?php

namespace App\Security;

use App\Model\Conversation;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ChatExecution
{
    public int $version;

    public function __construct(public readonly Conversation $conversation, public readonly string $attemptId)
    {
        $this->version = $conversation->version;
    }
}
