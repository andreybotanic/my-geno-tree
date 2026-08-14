<?php

namespace App\Application\Tree\Layout;

use App\Entity\FamilyTree;
use App\Repository\TreeLayoutRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class TreeLayoutSnapshotService
{
    public function __construct(
        private TreeLayoutRepository $layouts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     layoutRevision: string|null,
     *     layout: array{
     *         algorithmVersion: int,
     *         positions: array<string, array{0: float|int, 1: float|int}>
     *     }|null
     * }
     */
    public function create(FamilyTree $tree, TreeStructure $structure): array
    {
        $anchor = $structure->effectiveAnchor($tree);
        if (null === $anchor) {
            return ['layoutRevision' => null, 'layout' => null];
        }
        [$primary, $secondary] = $anchor;
        $revision = $structure->revision($primary, $secondary);
        $layout = $this->layouts->findForAnchor($tree, $primary, $secondary);
        if (null === $layout) {
            return ['layoutRevision' => $revision, 'layout' => null];
        }
        if (!hash_equals($revision, $layout->getLayoutRevision())) {
            $this->layouts->deleteById($layout->getId());

            return ['layoutRevision' => $revision, 'layout' => null];
        }

        $this->layouts->touchLastUsedAt($layout->getId(), $this->clock->now());

        return [
            'layoutRevision' => $revision,
            'layout'         => [
                'algorithmVersion' => $layout->getAlgorithmVersion(),
                'positions'        => $layout->getPositions(),
            ],
        ];
    }
}
