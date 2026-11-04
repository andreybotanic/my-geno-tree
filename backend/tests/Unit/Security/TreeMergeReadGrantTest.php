<?php

namespace App\Tests\Unit\Security;

use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\TreeMergeReadGrant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class TreeMergeReadGrantTest extends TestCase
{
    public function testGrantExpiresAndHasARequestBudget(): void
    {
        $clock = new MockClock();
        $grants = new TreeMergeReadGrant(new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock);
        $user = new User('test', 'test@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $clock->now()), null, 'etag', $clock->now());
        $token = $grants->issue($draft, 'attempt');
        for ($i = 31; $i >= 0; --$i) {
            self::assertSame($i, $grants->consume($token)['remaining']);
        }
        try {
            $grants->consume($token);
            self::fail('Exhausted grant accepted');
        } catch (AuthenticationException $error) {
            self::assertSame('invalid_merge_access', $error->getMessage());
        }
        $expiring = $grants->issue($draft, 'next');
        $clock->sleep(300);
        $this->expectException(AuthenticationException::class);
        $grants->consume($expiring);
    }
}
