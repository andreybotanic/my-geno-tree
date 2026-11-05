<?php

namespace App\Tests\Unit\Security;

use App\Entity\AuthSession;
use App\Entity\User;
use App\Model\Conversation;
use App\Security\ChatVoter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class ChatVoterTest extends TestCase
{
    /** @return list<array{string}> */
    public static function sessions(): array
    {
        return [['valid'], ['missing'], ['anonymous'], ['expired'], ['revoked'], ['different_user'], ['different_session']];
    }

    #[DataProvider('sessions')]
    public function testOnlyTheActiveLoginThatOwnsTheConversationCanUseIt(string $case): void
    {
        $clock = new MockClock();
        $user = new User('owner', 'owner@example.test', 'hash', 'en', $clock->now());
        $other = new User('other', 'other@example.test', 'hash', 'en', $clock->now());
        $session = new AuthSession('different_user' === $case ? $other : $user, 'hash', $clock->now()->modify('+1 hour'),
            $clock->now()->modify('expired' === $case ? '-1 second' : '+1 day'), $clock->now());
        if ('revoked' === $case) {
            $session->revoke($clock->now());
        }
        $conversation = new Conversation('test', 'general', [], 'different_session' === $case ? Uuid::v7() : $session->getId(), $user->getId(), $clock->now());
        $request = new Request();
        if ('missing' !== $case) {
            $request->attributes->set(AuthSession::class, $session);
        }
        $requests = new RequestStack();
        $requests->push($request);
        $token = 'anonymous' === $case ? new NullToken() : new PostAuthenticationToken($user, 'main', $user->getRoles());
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(in_array($case, ['missing', 'anonymous'], true) ? $this->never() : $this->once())->method('refresh')->with($session);
        $voter = new ChatVoter($requests, $manager, $clock);
        self::assertSame('valid' === $case ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $conversation, [ChatVoter::USE]));
    }

    public function testUnrelatedSubjectsAndPermissionsAreLeftToOtherVoters(): void
    {
        $voter = new ChatVoter(new RequestStack(), $this->createStub(EntityManagerInterface::class), new MockClock());
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new \stdClass(), [ChatVoter::USE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_USER']));
    }
}
