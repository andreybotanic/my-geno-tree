<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\MercureChatTransport;
use Psr\Log\NullLogger;
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

    public static function publisher(\Doctrine\ORM\EntityManagerInterface $entityManager, \Doctrine\Persistence\ManagerRegistry $registry, ?RequestStack $requests = null): MercureChatTransport
    {
        $hub = new MockHub('https://localhost/.well-known/mercure', new \Symfony\Component\Mercure\Jwt\StaticTokenProvider('test'), static fn () => 'event');

        return new MercureChatTransport($hub, $requests ?? new RequestStack(), new NullLogger(), 'https://localhost', $entityManager, new \Symfony\Component\Clock\NativeClock(), new \Symfony\Component\Mercure\Authorization(new \Symfony\Component\Mercure\HubRegistry($hub)), new \App\Repository\AuthSessionRepository($registry), str_repeat('s', 32), 'https://localhost');
    }
}
