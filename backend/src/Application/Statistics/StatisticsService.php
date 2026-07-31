<?php

namespace App\Application\Statistics;

use App\Repository\FamilyTreeRepository;
use App\Repository\UserRepository;

final readonly class StatisticsService
{
    public function __construct(
        private UserRepository $users,
        private FamilyTreeRepository $trees,
    ) {
    }

    /**
     * @return array{accountCount: int, treeCount: int}
     */
    public function current(): array
    {
        return [
            'accountCount' => $this->users->countAll(),
            'treeCount'    => $this->trees->countAll(),
        ];
    }
}
