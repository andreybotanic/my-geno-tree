<?php

namespace App\Tests\Unit\Security;

use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\FamilyTreeVoter;
use App\Security\TreeMergeAgentVoter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class TreeMergeAgentVoterTest extends TestCase
{
    #[DataProvider('invalidSessions')]
    public function testAnActiveLoginBelongingToTheCurrentUserIsRequired(string $case): void
    {
        $clock = new MockClock();
        $user = new User('owner', 'owner@example.test', 'hash', 'en', $clock->now());
        $other = new User('other', 'other@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'Family', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession('different_user' === $case ? $other : $user, 'hash', $clock->now()->modify('+1 hour'),
            $clock->now()->modify('expired' === $case ? '-1 second' : '+1 day'), $clock->now());
        if ('revoked' === $case) {
            $session->revoke($clock->now());
        }
        $request = new Request();
        if ('missing' !== $case) {
            $request->attributes->set(AuthSession::class, $session);
        }
        $requests = new RequestStack();
        $requests->push($request);
        $token = 'anonymous' === $case ? new NullToken() : new PostAuthenticationToken($user, 'main', $user->getRoles());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(in_array($case, ['missing', 'anonymous'], true) ? $this->never() : $this->once())
            ->method('refresh')->with($session);
        $decisions = $this->createMock(AccessDecisionManagerInterface::class);
        $decisions->expects($this->never())->method('decide');
        $voter = new TreeMergeAgentVoter($requests, $entityManager, $clock, $decisions);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $draft, [TreeMergeAgentVoter::USE]));
    }

    /** @return list<array{string}> */
    public static function invalidSessions(): array
    {
        return [['missing'], ['anonymous'], ['expired'], ['revoked'], ['different_user']];
    }

    #[DataProvider('ownershipDecisions')]
    public function testTreeOwnershipIsDecidedWithTheSameSecurityToken(bool $granted): void
    {
        $clock = new MockClock();
        $user = new User('owner', 'owner@example.test', 'hash', 'en', $clock->now());
        $draft = new TreeMergeDraft(new FamilyTree($user, 'Family', $clock->now()), null, 'etag', $clock->now());
        $session = new AuthSession($user, 'hash', $clock->now()->modify('+1 hour'), $clock->now()->modify('+1 day'), $clock->now());
        $request = new Request();
        $request->attributes->set(AuthSession::class, $session);
        $requests = new RequestStack();
        $requests->push($request);
        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('refresh')->with($session);
        $decisions = $this->createMock(AccessDecisionManagerInterface::class);
        $decisions->expects($this->once())->method('decide')->with($token, [FamilyTreeVoter::EDIT], $draft->tree)->willReturn($granted);
        $voter = new TreeMergeAgentVoter($requests, $entityManager, $clock, $decisions);

        self::assertSame($granted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $draft, [TreeMergeAgentVoter::USE]));
    }

    /** @return list<array{bool}> */
    public static function ownershipDecisions(): array
    {
        return [[true], [false]];
    }

    public function testUnrelatedSubjectsAndPermissionsAreLeftToOtherVoters(): void
    {
        $voter = new TreeMergeAgentVoter(new RequestStack(), $this->createStub(EntityManagerInterface::class),
            new MockClock(), $this->createStub(AccessDecisionManagerInterface::class));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new \stdClass(), [TreeMergeAgentVoter::USE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_USER']));
    }
}
