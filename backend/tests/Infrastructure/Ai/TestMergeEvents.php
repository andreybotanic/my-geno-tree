<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\MercureChatEventPublisher;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class TestMergeEvents
{
    /** @var list<Update> */
    public static array $updates = [];

    /** @return list<Update> */
    public static function updates(): array
    {
        return self::$updates;
    }

    public static function hub(HubInterface $inner): MockHub
    {
        return new MockHub(
            $inner->getPublicUrl(),
            new \Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'),
            static function (Update $update): string {
                self::$updates[] = $update;

                return 'event';
            },
            $inner->getFactory(),
            cookieName: $inner->getCookieName()
        );
    }

    public static function publisher(AuthorizationCheckerInterface $authorization): MercureChatEventPublisher
    {
        $hub = new MockHub('https://localhost/.well-known/mercure', new \Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'), static fn () => 'event');

        return new MercureChatEventPublisher($hub, $authorization, new NullLogger(), 'https://localhost/api/v1/chat-events/sessions/{sessionId}/conversations/');
    }
}
