<?php

namespace App\Application\Tree;

use App\Application\Media\MediaService;
use App\Entity\FamilyTree;
use App\Repository\GenealogicalEventRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteTreeService
{
    public function __construct(
        private TreeVersion $versions,
        private MediaService $media,
        private GenealogicalEventRepository $events,
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
            $places = [];
            foreach ($this->events->findAllForTree($tree) as $event) {
                $place = $event->getPlace();
                $placeId = $place?->getId();
                if (null !== $place && null !== $placeId) {
                    $places[$placeId->toRfc4122()] = $place;
                }
            }
            foreach ($places as $place) {
                $this->entityManager->remove($place);
            }
            $this->entityManager->remove($tree);
        });
        $this->media->deleteStored($removedMedia);
    }
}
