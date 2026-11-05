<?php

namespace App\Security;

use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, TreeMergeDraft> */
final class TreeMergeAgentVoter extends Voter
{
    public const string USE = 'TREE_MERGE_AGENT_USE';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::USE === $attribute && $subject instanceof TreeMergeDraft;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $session = $this->requests->getCurrentRequest()?->attributes->get(AuthSession::class);
        if (!$user instanceof User || !$session instanceof AuthSession) {
            $vote?->addReason('An authenticated login session is required.');

            return false;
        }
        // Recheck logout even while a long-running request retains its security token.
        $this->entityManager->refresh($session);
        if (!$session->isRefreshActive($this->clock->now()) || !$session->getUser()->getId()->equals($user->getId())) {
            $vote?->addReason('The current login session is inactive or belongs to another user.');

            return false;
        }

        return $this->decisions->decide($token, [FamilyTreeVoter::EDIT], $subject->tree);
    }
}
