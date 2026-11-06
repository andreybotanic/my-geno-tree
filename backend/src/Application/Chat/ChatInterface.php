<?php

namespace App\Application\Chat;

use App\Model\Conversation;
use App\Security\ChatExecution;
use Symfony\Component\Uid\Uuid;

/** Application API shared by HTTP endpoints and tool integrations; Symfony AI executes the agent. */
interface ChatInterface
{
    /** @param array<string, string|null> $context */
    public function open(string $contextKey, string $profile, array $context): Conversation;

    /** @phpstan-impure */
    public function current(string $contextKey): ?Conversation;

    public function get(string $id): Conversation;

    /** @param array<string, mixed> $payload */
    public function send(Conversation $conversation, array $payload): Conversation;

    public function stop(Conversation $conversation, mixed $requestId): Conversation;

    public function reset(Conversation $conversation, mixed $version): void;

    public function execution(): ChatExecution;

    public function replaceTool(Conversation $conversation, string $name, ?object $state, bool $invalidate = true): Conversation;

    /** @return array<string, mixed> */
    public function snapshot(Conversation $conversation): array;

    /** @return list<array{id: string, requestId: string, role: string, content: string, createdAt: string, failureCode?: string}> */
    public function messages(Conversation $conversation): array;

    /** @return array{remaining: int, limit: int} */
    public function budget(Uuid $user): array;
}
