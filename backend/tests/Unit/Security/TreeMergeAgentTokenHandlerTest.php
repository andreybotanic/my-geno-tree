<?php

namespace App\Tests\Unit\Security;

use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\FamilyTreeVoter;
use App\Security\TreeMergeAgentTokenHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Uid\UuidV4;

final class TreeMergeAgentTokenHandlerTest extends TestCase
{
    public function testTokenIsReusedAcrossMessagesAndAuthenticatesItsLoginWithoutProtocolRequestLimits(): void
    {
        $clock = new MockClock();
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $tree = new FamilyTree($user, 'tree', $clock->now());
        $draft = new TreeMergeDraft($tree, null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $otherSession = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $handler = $this->handler($clock, $session, $otherSession);
        self::assertInstanceOf(AccessTokenHandlerInterface::class, $handler);
        $token = $handler->issue($draft, $session);
        self::assertInstanceOf(UuidV4::class, UuidV4::fromString($token));
        $draft->beginAttempt($clock->now());
        self::assertSame($token, $handler->issue($draft, $session));
        for ($i = 0; $i < 100; ++$i) {
            $badge = $handler->getUserBadgeFrom($token);
            self::assertSame($user, $badge->getUser());
            $attributes = $badge->getAttributes();
            self::assertIsArray($attributes);
            self::assertArrayHasKey('draftId', $attributes);
            self::assertSame($draft->id->toRfc4122(), $attributes['draftId']);
            self::assertSame($session, $attributes['session']);
        }
        self::assertNotSame($token, $handler->issue(new TreeMergeDraft($tree, null, 'etag', $clock->now()), $session));
        self::assertNotSame($token, $handler->issue($draft, $otherSession));
        $handler->revoke($token);
        self::assertNotSame($token, $handler->issue($draft, $session));
        $this->expectException(AuthenticationException::class);
        $handler->getUserBadgeFrom($token);
    }

    public function testExpiredTokenIsRejectedAndReplacedOnNextMessage(): void
    {
        $clock = new MockClock();
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $handler = $this->handler($clock, $session);
        $token = $handler->issue($draft, $session);
        $clock->sleep(3600);
        self::assertNotSame($token, $handler->issue($draft, $session));
        $this->expectException(AuthenticationException::class);
        $handler->getUserBadgeFrom($token);
    }

    public function testTokenCannotBeIssuedWithoutALogin(): void
    {
        $clock = new MockClock();
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $this->expectException(AuthenticationException::class);
        $this->handler($clock)->issue($draft);
    }

    public function testIssuingUsesTheTreeVoterToRejectAnotherUsersTree(): void
    {
        $clock = new MockClock();
        $owner = new User('owner', 'owner@example.test', 'hash', 'en', $clock->now());
        $other = new User('other', 'other@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($owner, 'tree', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($other, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $this->expectException(AuthenticationException::class);
        $this->handler($clock, $session)->issue($draft, $session);
    }

    public function testRevokedLoginInvalidatesAnExistingToken(): void
    {
        $clock = new MockClock();
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $handler = $this->handler($clock, $session);
        $token = $handler->issue($draft, $session);
        $session->revoke($clock->now());
        $this->expectException(AuthenticationException::class);
        $handler->getUserBadgeFrom($token);
    }

    public function testDeletedLoginInvalidatesAnExistingToken(): void
    {
        $clock = new MockClock();
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $handler = $this->handler($clock);
        $token = $handler->issue($draft, $session);
        $this->expectException(AuthenticationException::class);
        $handler->getUserBadgeFrom($token);
    }

    private function handler(MockClock $clock, AuthSession ...$sessions): TreeMergeAgentTokenHandler
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(static function (string $className, mixed $id) use ($sessions): ?AuthSession {
            self::assertSame(AuthSession::class, $className);

            return array_find($sessions, static fn (AuthSession $session) => $session->getId()->toRfc4122() === $id);
        });

        return new TreeMergeAgentTokenHandler(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, new RequestStack(),
            $entityManager, new AccessDecisionManager([new FamilyTreeVoter()]));
    }
}
