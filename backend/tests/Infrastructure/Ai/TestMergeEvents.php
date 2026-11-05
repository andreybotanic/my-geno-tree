<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\ChatEvents;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

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
        return new MockHub($inner->getPublicUrl(), new \Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'),
            static function (Update $update): string {
                self::$updates[] = $update;

                return 'event';
            }, $inner->getFactory(), cookieName: $inner->getCookieName());
    }

    public static function publisher(\Doctrine\ORM\EntityManagerInterface $entityManager): ChatEvents
    {
        $hub = new MockHub('https://localhost/.well-known/mercure', new \Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'), static fn () => 'event');

        return new ChatEvents($hub, new ArrayAdapter(), new RequestStack(), new NullLogger(), 'https://localhost', $entityManager, new \Symfony\Component\Clock\NativeClock());
    }
}
