<?php

namespace App\Security;

use App\Entity\FamilyTree;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, FamilyTree>
 */
final class FamilyTreeVoter extends Voter
{
    public const string EDIT = 'TREE_EDIT';
    public const string VIEW = 'TREE_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FamilyTree
            && in_array($attribute, [self::VIEW, self::EDIT], true);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $subject->getOwner()->getId()->equals($user->getId());
    }
}
