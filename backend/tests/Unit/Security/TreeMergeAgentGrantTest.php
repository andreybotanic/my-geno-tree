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

final class TreeMergeAgentGrantTest extends TestCase
{
    public function testGrantExpiresAndHasARequestBudget(): void
    {
        $clock = new MockClock();
        $grants = new TreeMergeAgentGrant(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, new RequestStack());
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $token = $grants->issue($draft, 'attempt', $session);
        for ($i = 63; $i >= 0; --$i) {
            self::assertSame($i, $grants->consume($token)['remaining']);
        }
        try {
            $grants->consume($token);
            self::fail('Exhausted grant accepted');
        } catch (AuthenticationException $error) {
            self::assertSame('invalid_merge_access', $error->getMessage());
        }
        $expiring = $grants->issue($draft, 'next', $session);
        $clock->sleep(300);
        $this->expectException(AuthenticationException::class);
        $grants->consume($expiring);
    }
}
