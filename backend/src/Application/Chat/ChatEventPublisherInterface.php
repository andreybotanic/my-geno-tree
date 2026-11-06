<?php

namespace App\Application\Chat;

use App\Model\Conversation;

/** Application notifications, independent of their delivery mechanism. */
interface ChatEventPublisherInterface
{
    public function progress(Conversation $conversation): void;

    /** @param array<string, mixed> $view */
    public function conversation(Conversation $conversation, array $view): void;

    public function removed(Conversation $conversation): void;

    /** @param array<string, mixed> $result */
    public function toolApplied(Conversation $conversation, string $tool, string $operationId, array $result): void;
}
