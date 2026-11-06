<?php

namespace App\Application\Chat\Moderation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Stops inference so the chat can return a localized policy notice. */
#[Exclude]
final class ModerationWarning extends \RuntimeException
{
    public function __construct(public readonly int $number)
    {
        parent::__construct('Chat policy warning.');
    }
}
