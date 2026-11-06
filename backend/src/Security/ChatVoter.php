<?php

namespace App\Security;

use App\Entity\AuthSession;
use App\Entity\User;
use App\Model\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Conversation> */
final class ChatVoter extends Voter
{
    public const string USE = 'CHAT_USE';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::USE === $attribute && $subject instanceof Conversation;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        $user = $token->getUser();
        if (!$session instanceof AuthSession || !$user instanceof User) {
            return false;
        }
        $this->entityManager->refresh($session);

        return $session->isRefreshActive($this->clock->now()) && $subject->sessionId->equals($session->getId())
            && $subject->userId->equals($user->getId()) && $session->getUser()->getId()->equals($user->getId());
    }
}
