<?php

namespace App\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\Hydration\GenealogyRecordHydrator;
use Doctrine\ORM\QueryBuilder;

/** Shared keyset mechanics for entity repositories; query builders remain inside the persistence layer. */
trait ReadsGenealogyPages
{
    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function genealogyPage(QueryBuilder $query, ?string $after, int $limit): array
    {
        $total = (int)(clone $query)
            ->select('COUNT(item.id)')
            ->getQuery()
            ->getSingleScalarResult();
        if (null !== $after) {
            $query
                ->andWhere('item.id > :after')
                ->setParameter('after', $after);
        }
        /** @var list<array<string, mixed>> $items */
        $items = $query
            ->orderBy('item.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(GenealogyRecordHydrator::MODE);

        return ['total' => $total, 'items' => $items];
    }
}
