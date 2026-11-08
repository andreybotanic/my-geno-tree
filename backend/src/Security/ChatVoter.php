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
use Symfony\Component\Uid\Uuid;

/** @extends Voter<string, Conversation|Uuid> */
final class ChatVoter extends Voter
{
    public const string USE = 'CHAT_USE';
    public const string INTERACT = 'CHAT_INTERACT';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::USE === $attribute && $subject instanceof Conversation)
            || (self::INTERACT === $attribute && ($subject instanceof Conversation || $subject instanceof Uuid));
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
        if (!$this->entityManager->contains($session)) {
            $session = $this->entityManager->find(AuthSession::class, $session->getId());
            if (!$session instanceof AuthSession) {
                return false;
            }
        }
        $this->entityManager->refresh($session);

        if (self::INTERACT === $attribute) {
            if (!$this->entityManager->contains($user)) {
                $user = $this->entityManager->find(User::class, $user->getId());
                if (!$user instanceof User) {
                    return false;
                }
            }
            $this->entityManager->refresh($user);
            if ($user->hasChatBan($this->clock->now())) {
                return false;
            }
            if ($subject instanceof Uuid) {
                return $session->isRefreshActive($this->clock->now())
                    && $subject->equals($user->getId()) && $session->getUser()->getId()->equals($user->getId());
            }
        }

        return $subject instanceof Conversation && $session->isRefreshActive($this->clock->now())
            && $subject->sessionId->equals($session->getId())
            && $subject->userId->equals($user->getId()) && $session->getUser()->getId()->equals($user->getId());
    }
}
