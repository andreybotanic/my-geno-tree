<?php

namespace App\Api;

use App\Application\Chat\Chat;
use App\Application\Chat\ChatActivity;
use App\Application\Chat\ChatTokenBudget;
use App\Model\Conversation;

final readonly class ChatViewFactory
{
    public function __construct(private Chat $chat, private ChatActivity $activity, private ChatTokenBudget $budget)
    {
    }

    /** @return array<string, mixed> */
    public function create(Conversation $conversation): array
    {
        $activity = $this->activity->read($conversation);

        return ['id'           => $conversation->id->toRfc4122(), 'profile' => $conversation->profile, 'version' => $conversation->version,
            'status'           => $conversation->status, 'attemptId' => $conversation->attemptId,
            'attemptStartedAt' => $conversation->attemptStartedAt?->format(\DateTimeInterface::ATOM),
            'messages'         => $this->chat->messages($conversation), 'tools' => (object)$conversation->tools,
            'progress'         => $activity['progress'], 'tokenUsage' => $activity['usage'], 'tokenBudget' => $this->budget->remaining($conversation->userId),
            'createdAt'        => $conversation->createdAt->format(\DateTimeInterface::ATOM), 'updatedAt' => $conversation->updatedAt->format(\DateTimeInterface::ATOM)];
    }
}
