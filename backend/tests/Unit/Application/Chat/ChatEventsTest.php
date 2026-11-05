<?php

namespace App\Tests\Unit\Application\Chat;

use App\Api\ApiException;
use App\Application\Chat\ChatEvents;
use App\Application\Chat\ChatSubscription;
use App\Entity\AuthSession;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class ChatEventsTest extends TestCase
{
    public function testAttemptBindingWorksWithoutRequestForMcpAndDoesNotExposeContexts(): void
    {
        /** @var \ArrayObject<int, Update> $updates */
        $updates = new \ArrayObject();
        $hub = new MockHub('https://localhost/.well-known/mercure', new StaticTokenProvider('test'), static function (Update $update) use (&$updates): string {
            $updates[] = $update;

            return 'event';
        });
        $requests = new RequestStack();
        $events = new ChatEvents($hub, new ArrayAdapter(), $requests, new NullLogger(), 'https://localhost', $this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new MockClock());
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', 'en', $now);
        $draft = \App\Tests\Infrastructure\Ai\ConversationFixture::create($user);
        $draft->beginAttempt($now, 'turn');
        $attempt = $draft->attemptId;
        self::assertNotNull($attempt);
        $events->begin($draft);
        $events->progress($draft->id->toRfc4122(), $attempt, ['thinking'], ['input' => 0, 'output' => 0, 'total' => 0]);
        self::assertCount(0, $updates);
        $request = new Request();
        $session = new AuthSession($user, 'hash', $now->modify('+15 minutes'), $now->modify('+1 day'), $now);
        $request->attributes->set(AuthSession::class, $session);
        $requests->push($request);
        $events->begin($draft);
        $events->progress($draft->id->toRfc4122(), $attempt, ['thinking', 'tree_loaded'], ['input' => 10, 'output' => 5, 'total' => 15]);
        self::assertCount(1, $updates);
        self::assertNotNull($updates[0]);
        self::assertSame([$events->sessionTopic($session) . $draft->id->toRfc4122()], $updates[0]->getTopics());
        /** @var array{attemptId: string, version: int, tokenUsage: array{total: int}} $packet */
        $packet = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($attempt, $packet['attemptId']);
        self::assertSame($draft->version, $packet['version']);
        self::assertSame(15, $packet['tokenUsage']['total']);
        self::assertArrayNotHasKey('contexts', $packet);
        $events->progress($draft->id->toRfc4122(), 'stale-attempt', [], ['input' => 0, 'output' => 0, 'total' => 0]);
        $events->conversation($session, []);
        self::assertCount(1, $updates);
    }

    public function testHubFailureDoesNotUndoCommittedMutationAndSubscriptionRequiresActiveSession(): void
    {
        $hub = new MockHub('https://localhost/.well-known/mercure', new StaticTokenProvider('test'), static fn () => throw new \RuntimeException('offline'));
        $events = new ChatEvents($hub, new ArrayAdapter(), new RequestStack(), new NullLogger(), 'https://localhost', $this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new MockClock());
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', 'en', $now);
        $session = new AuthSession($user, 'hash', $now, $now, $now);
        $events->conversation($session, ['id' => 'draft']);
        $subscription = new ChatSubscription(new Authorization(new HubRegistry($hub)), $events, new MockClock($now),
            new \App\Repository\AuthSessionRepository($this->createStub(\Doctrine\Persistence\ManagerRegistry::class)), str_repeat('s', 32), 'https://localhost');
        $request = new Request();
        $request->attributes->set(AuthSession::class, $session);
        $this->expectException(ApiException::class);
        $subscription->create($request, \App\Tests\Infrastructure\Ai\ConversationFixture::create($user));
    }
}
