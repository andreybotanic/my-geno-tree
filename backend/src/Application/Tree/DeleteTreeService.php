<?php

namespace App\Application\Tree;

use App\Application\Media\MediaService;
use App\Entity\FamilyTree;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteTreeService
{
    public function __construct(
        private TreeVersion $versions,
        private MediaService $media,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function delete(FamilyTree $tree, ?string $ifMatch): void
    {
        $removedMedia = [];
        $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $ifMatch,
            &$removedMedia,
        ): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);
            $removedMedia = $this->media->detachTreeMediaRecords($tree);
            $this->entityManager->remove($tree);
        });
        $this->media->deleteStored($removedMedia);
    }
}
