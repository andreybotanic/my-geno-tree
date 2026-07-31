<?php

namespace App\Application\Profile;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class ProfileService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function changeLanguage(User $user, string $language): void
    {
        $user->changePreferredLanguage($language, $this->clock->now());
        $this->entityManager->flush();
    }
}
