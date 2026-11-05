<?php

namespace App\Tests\Unit\Security;

use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\TreeMergeAgentGrant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Uid\UuidV4;

final class TreeMergeAgentGrantTest extends TestCase
{
    public function testGrantIsReusedAcrossMessagesButSeparatedByChatAndLoginSession(): void
    {
        $clock = new MockClock();
        $grants = new TreeMergeAgentGrant(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, new RequestStack());
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $tree = new FamilyTree($user, 'tree', $clock->now());
        $draft = new TreeMergeDraft($tree, null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $token = $grants->issue($draft, $session);
        self::assertInstanceOf(UuidV4::class, UuidV4::fromString($token));
        $draft->beginAttempt($clock->now());
        self::assertSame($token, $grants->issue($draft, $session));
        for ($i = 0; $i < 100; ++$i) {
            self::assertSame($draft->id->toRfc4122(), $grants->resolve($token)['draftId']);
        }
        self::assertNotSame($token, $grants->issue(new TreeMergeDraft($tree, null, 'etag', $clock->now()), $session));
        $otherSession = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        self::assertNotSame($token, $grants->issue($draft, $otherSession));
        $grants->revoke($token);
        self::assertNotSame($token, $grants->issue($draft, $session));
        $this->expectException(AuthenticationException::class);
        $grants->resolve($token);
    }

    public function testExpiredGrantCannotBeUsedAndIsReplacedOnNextMessage(): void
    {
        $clock = new MockClock();
        $grants = new TreeMergeAgentGrant(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, new RequestStack());
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $token = $grants->issue($draft, $session);
        $clock->sleep(3600);
        self::assertNotSame($token, $grants->issue($draft, $session));
        $this->expectException(AuthenticationException::class);
        $grants->resolve($token);
    }

    public function testGrantCannotBeIssuedWithoutAnActiveOwnerLogin(): void
    {
        $clock = new MockClock();
        $grants = new TreeMergeAgentGrant(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, new RequestStack());
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $this->expectException(AuthenticationException::class);
        $grants->issue($draft);
    }
}
