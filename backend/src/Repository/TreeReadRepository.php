<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Bounded, media-free ORM projections shared by chat and MCP. */
final readonly class TreeReadRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param 'people'|'parents'|'marriages'|'events' $kind
     * @param list<string>|null $personIds
     * @param list<string>|null $types
     */
    public function query(
        FamilyTree $tree,
        string $kind,
        ?array $personIds,
        ?array $types = null,
        ?string $source = null,
        bool $places = true,
    ): QueryBuilder {
        $class = match ($kind) {
            'people'    => Person::class,
            'parents'   => ParentLink::class,
            'marriages' => MarriagePeriod::class,
            'events'    => GenealogicalEvent::class,
        };
        $query = $this->entityManager
            ->createQueryBuilder()
            ->select('item')
            ->from($class, 'item')
            ->setParameter('tree', $tree);
        if ('events' === $kind) {
            $query
                ->join('item.person', 'owner')
                ->addSelect('IDENTITY(item.person) AS personId')
                ->andWhere('owner.tree = :tree');
            if (null !== $types) {
                $query
                    ->andWhere('item.type IN (:types)')
                    ->setParameter('types', $types);
            }
            if (null !== $source) {
                $query
                    ->andWhere('item.source = :source')
                    ->setParameter('source', $source);
            }
        } else {
            $query->andWhere('item.tree = :tree');
        }
        if ('parents' === $kind) {
            $query->addSelect('IDENTITY(item.parent) AS parentId', 'IDENTITY(item.child) AS childId');
        } elseif ('marriages' === $kind) {
            $query->addSelect(
                'IDENTITY(item.malePerson) AS malePersonId',
                'IDENTITY(item.femalePerson) AS femalePersonId',
            );
        }
        if ($places) {
            foreach (match ($kind) {
                'people'    => ['birthPlace', 'deathPlace'],
                'marriages' => ['marriagePlace', 'dissolutionPlace'],
                'events'    => ['place'],
                'parents'   => [],
            } as $place) {
                $query
                    ->leftJoin('item.' . $place, $place)
                    ->addSelect($place);
            }
        }
        if (null !== $personIds) {
            if ([] === $personIds) {
                $query->andWhere('1 = 0');
            } else {
                $query
                    ->andWhere(
                        match ($kind) {
                            'people'    => 'item.id IN (:people)',
                            'parents'   => 'item.parent IN (:people) OR item.child IN (:people)',
                            'marriages' => 'item.malePerson IN (:people) OR item.femalePerson IN (:people)',
                            'events'    => 'item.person IN (:people)',
                        },
                    )
                    ->setParameter('people', $personIds);
            }
        }

        return $query;
    }

    public function total(QueryBuilder $query): int
    {
        return (int)(clone $query)
            ->select('COUNT(item.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<array<string, mixed>> */
    public function page(QueryBuilder $query, ?string $after, int $limit): array
    {
        if (null !== $after) {
            $query
                ->andWhere('item.id > :after')
                ->setParameter('after', $after);
        }
        /** @var list<array<array-key, mixed>> $rows */
        $rows = $query
            ->orderBy('item.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            if (isset($row[0]) && is_array($row[0])) {
                $item = $row[0];
                unset($row[0]);
                $row = [...$item, ...$row];
            }

            /* @var array<string, mixed> $row */
            return $row;
        }, $rows);
    }
}
