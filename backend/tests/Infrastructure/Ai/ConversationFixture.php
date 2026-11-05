<?php

namespace App\Tests\Infrastructure\Ai;

use App\Entity\User;
use App\Model\Conversation;
use Symfony\Component\Uid\Uuid;

final class ConversationFixture
{
    public static function create(?User $user = null): Conversation
    {
        return new Conversation('test', 'tree', [], Uuid::v7(), $user?->getId() ?? Uuid::v7(), new \DateTimeImmutable());
    }
}
