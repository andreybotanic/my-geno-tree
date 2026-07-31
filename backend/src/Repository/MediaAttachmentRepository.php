<?php

namespace App\Repository;

use App\Entity\FamilyTree;
use App\Entity\MediaAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaAttachment>
 */
final class MediaAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaAttachment::class);
    }

    /** @return list<MediaAttachment> */
    public function findAllForTree(FamilyTree $tree): array
    {
        /** @var list<MediaAttachment> $attachments */
        $attachments = $this->createQueryBuilder('attachment')
            ->addSelect('media', 'variant')
            ->innerJoin('attachment.person', 'person')
            ->innerJoin('attachment.media', 'media')
            ->leftJoin('media.variants', 'variant')
            ->andWhere('person.tree = :tree')
            ->setParameter('tree', $tree)
            ->getQuery()
            ->getResult();

        return $attachments;
    }
}
