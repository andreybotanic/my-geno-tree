<?php

namespace App\Application\Tree\Layout;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\TreeLayout;
use App\Repository\TreeLayoutRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class SaveTreeLayoutService
{
    /** Maximum derived layouts retained for one tree. Change this value to adjust the LRU capacity. */
    public const int MAX_SAVED_LAYOUTS_PER_TREE = 10;

    private const int MAX_ALGORITHM_VERSION = 1_000_000;
    private const int MAX_ABSOLUTE_COORDINATE = 1_000_000;

    public function __construct(
        private TreeStructureLoader $structures,
        private TreeLayoutRepository $layouts,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function save(FamilyTree $tree, array $payload): void
    {
        $this->assertExactKeys($payload, ['layoutRevision', 'algorithmVersion', 'positions']);
        $layoutRevision = $payload['layoutRevision'];
        if (!is_string($layoutRevision) || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $layoutRevision)) {
            $this->invalid('layoutRevision', 'layout_revision_invalid');
        }
        $algorithmVersion = $payload['algorithmVersion'];
        if (!is_int($algorithmVersion) || $algorithmVersion < 1 || $algorithmVersion > self::MAX_ALGORITHM_VERSION) {
            $this->invalid('algorithmVersion', 'layout_algorithm_version_invalid');
        }

        $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $payload,
            $layoutRevision,
            $algorithmVersion,
        ): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $structure = $this->structures->load($tree);
            $anchor = $structure->effectiveAnchor($tree);
            if (null === $anchor) {
                $this->conflict();
            }
            $primary = $anchor[0];
            $secondary = $anchor[1];
            $currentRevision = $structure->revision($primary, $secondary);
            if (!hash_equals($currentRevision, $layoutRevision)) {
                $this->conflict();
            }

            $positions = $this->positions(
                $payload['positions'],
                $structure->visiblePersonIds($primary, $secondary),
            );
            $savedLayouts = $this->layouts->findAllForTreeForUpdate($tree);
            $existing = $this->matchingLayout($savedLayouts, $primary, $secondary);
            $now = $this->clock->now();
            if (null !== $existing) {
                $existing->replace($layoutRevision, $algorithmVersion, $positions, $now);

                return;
            }

            $evictionCount = count($savedLayouts) - self::MAX_SAVED_LAYOUTS_PER_TREE + 1;
            foreach (array_slice($savedLayouts, 0, max(0, $evictionCount)) as $layoutToEvict) {
                $this->entityManager->remove($layoutToEvict);
            }
            $this->entityManager->persist(new TreeLayout(
                $tree,
                $primary,
                $secondary,
                $layoutRevision,
                $algorithmVersion,
                $positions,
                $now,
            ));
        });
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

    /**
     * @param list<string> $expectedPersonIds
     *
     * @return array<string, array{0: float|int, 1: float|int}>
     */
    private function positions(mixed $value, array $expectedPersonIds): array
    {
        if (!is_array($value)) {
            $this->invalid('positions', 'layout_positions_invalid');
        }

        $positions = [];
        foreach ($value as $personId => $coordinates) {
            if (
                !is_string($personId)
                || !is_array($coordinates)
                || !array_is_list($coordinates)
                || 2 !== count($coordinates)
                || (!is_int($coordinates[0]) && !is_float($coordinates[0]))
                || (!is_int($coordinates[1]) && !is_float($coordinates[1]))
                || !is_finite((float)$coordinates[0])
                || !is_finite((float)$coordinates[1])
                || abs((float)$coordinates[0]) > self::MAX_ABSOLUTE_COORDINATE
                || abs((float)$coordinates[1]) > self::MAX_ABSOLUTE_COORDINATE
            ) {
                $this->invalid('positions', 'layout_positions_invalid');
            }
            $positions[$personId] = [$coordinates[0], $coordinates[1]];
        }
        ksort($positions);
        $actualPersonIds = array_keys($positions);
        if ($actualPersonIds !== $expectedPersonIds) {
            $this->invalid('positions', 'layout_person_set_invalid');
        }

        return $positions;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $expected
     */
    private function assertExactKeys(array $payload, array $expected): void
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $expected, true)) {
                $this->invalid($key, 'field_not_allowed');
            }
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->invalid($key, 'field_required');
            }
        }
    }

    private function conflict(): never
    {
        throw new ApiException(JsonResponse::HTTP_CONFLICT, 'LAYOUT_REVISION_CONFLICT', 'layout_revision_conflict');
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'LAYOUT_INVALID', 'layout_invalid', [$field => [$error]]);
    }
}
