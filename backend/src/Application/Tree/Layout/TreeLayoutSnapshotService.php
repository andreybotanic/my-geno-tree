<?php

namespace App\Application\Tree\Layout;

use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\TreeLayout;
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
        $layout = $this->matchingLayout(
            $this->layouts->findAllForTree($tree),
            $primary,
            $secondary,
        );
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

    /**
     * @param list<TreeLayout> $layouts
     */
    private function matchingLayout(
        array $layouts,
        Person $primary,
        ?Person $secondary,
    ): ?TreeLayout {
        $primaryId = $primary->getId()->toRfc4122();
        $secondaryId = $secondary?->getId()->toRfc4122();
        foreach ($layouts as $layout) {
            if (
                $layout->getPrimaryPerson()->getId()->toRfc4122() === $primaryId
                && $layout->getSecondaryPerson()?->getId()->toRfc4122() === $secondaryId
            ) {
                return $layout;
            }
        }

        return null;
    }
}
