<?php

namespace App\Application\Statistics;

use App\Repository\FamilyTreeRepository;
use App\Repository\PersonRepository;
use App\Repository\UserRepository;

final readonly class StatisticsService
{
    public function __construct(
        private UserRepository $users,
        private FamilyTreeRepository $trees,
        private PersonRepository $people,
    ) {
    }

    /**
     * @return array{accountCount: int, treeCount: int, personCount: int}
     */
    public function current(): array
    {
        return [
            'accountCount' => $this->users->countAll(),
            'treeCount'    => $this->trees->countAll(),
            'personCount'  => $this->people->countAll(),
        ];
    }
}
