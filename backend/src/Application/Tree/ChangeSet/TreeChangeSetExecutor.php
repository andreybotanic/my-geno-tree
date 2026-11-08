<?php

namespace App\Application\Tree\ChangeSet;

use App\Api\ApiException;
use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Media\MediaService;
use App\Application\Tree\Layout\TreeLayoutInvalidator;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class TreeChangeSetExecutor
{
    public function __construct(
        private TreeChangeSetProjector $projector,
        private TreeVersion $versions,
        private AutomaticEventSynchronizer $events,
        private ParentLinkRepository $parents,
        private TreeLayoutInvalidator $layouts,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TreeOperationExecutor $operations,
        private MarriagePeriodRepository $marriages,
        private MediaService $media,
    ) {
    }

    /**
     * Owns the application transaction; stored media cleanup follows a successful commit.
     *
     * @return array{
     *     personIds: array<string, string>, affectedPersonIds: list<string>,
     *     newPersonIds: list<string>, etag: string
     * }
     */
    public function execute(FamilyTree $tree, TreeChangeSet $changes, string $ifMatch): array
    {
        $removedMedia = [];
        $result = $this->entityManager->wrapInTransaction(
            function () use ($tree, $changes, $ifMatch, &$removedMedia): ?array {
                $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($tree);
                // A read-only conflict must leave the manager usable for refreshing the pending review.
                if ($this->versions->etag($tree) !== trim($ifMatch)) {
                    return null;
                }
                $now = $this->clock->now();
                $projection = $this->projector->project($tree, $changes, $now, true);
                $removedMedia = $this->operations->remove($tree, $projection, $now);
                $this->entityManager->flush();
                $managed = $projection->originals;
                $ids = $affected = $newIds = [];
                foreach ($changes->people as $change) {
                    $projected = $projection->people[$change->reference];
                    $id = $projected->getId()->toRfc4122();
                    if (null === $change->existingPersonId) {
                        $person = $projected;
                        $newIds[] = $id;
                        $this->entityManager->persist($person);
                        if (null === $tree->getReferencePrimaryPerson()) {
                            $tree->referencePerson($person);
                        }
                    } else {
                        $person = $managed[$id];
                        if (!$change->changes($person)) {
                            $ids[$change->reference] = $id;
                            continue;
                        }
                        $deathPlace = null === $projected->getDeathDate() ? $person->getDeathPlace() : null;
                        $person->update(
                            $projected->getBirthSurname(),
                            $projected->getGivenName(),
                            $projected->getPatronymic(),
                            $projected->getGender(),
                            $projected->getBirthDate(),
                            $projected->getDeathDate(),
                            $now,
                        );
                        if (null !== $deathPlace) {
                            $this->entityManager->remove($deathPlace);
                        }
                    }
                    $this->operations->personPlaces($person, $change->places, $now);
                    $managed[$id] = $person;
                    $ids[$change->reference] = $id;
                    $affected[$id] = true;
                }
                // Lifecycle event queries require newly created people to exist; still inside the same transaction.
                $this->entityManager->flush();
                foreach ($affected as $id => $_) {
                    $this->events->synchronizePersonLifecycle($managed[$id], $now);
                }
                foreach ($projection->addedParents as $link) {
                    $p = $link->getParent()->getId()->toRfc4122();
                    $c = $link->getChild()->getId()->toRfc4122();
                    $new = new ParentLink($tree, $managed[$p], $managed[$c], $link->getRole());
                    $this->entityManager->persist($new);
                    $this->events->synchronizeParentLink($new, $now);
                    $affected[$p] = $affected[$c] = true;
                }
                $newMarriages = [];
                foreach ($projection->addedMarriages as $marriage) {
                    $m = $marriage->getMalePerson()->getId()->toRfc4122();
                    $f = $marriage->getFemalePerson()->getId()->toRfc4122();
                    $new = new MarriagePeriod(
                        $tree,
                        $managed[$m],
                        $managed[$f],
                        $marriage->getMarriageDate(),
                        $marriage->getDissolutionDate(),
                        $now,
                    );
                    $this->entityManager->persist($new);
                    foreach ($changes->marriages as $change) {
                        $first = $projection->people[$change->first];
                        $second = $projection->people[$change->second];
                        if (in_array($first->getId()->toRfc4122(), [$m, $f], true)
                            && in_array($second->getId()->toRfc4122(), [$m, $f], true)
                            && $change->marriageDate == $marriage->getMarriageDate()
                            && $change->dissolutionDate == $marriage->getDissolutionDate()) {
                            $this->operations->marriagePlaces($new, $change->places, $now);
                            $newMarriages[] = [$new, $change->maleNewSurname, $change->femaleNewSurname];
                            break;
                        }
                    }
                    $affected[$m] = $affected[$f] = true;
                }
                foreach ($projection->updatedMarriages as $id => $projected) {
                    $marriage = $this->marriages->findInTree($tree, $id);
                    if (null !== $marriage) {
                        $this->operations->updateMarriage($marriage, $projected, $changes, $now);
                        $affected[$marriage->getMalePerson()->getId()->toRfc4122()] = true;
                        $affected[$marriage->getFemalePerson()->getId()->toRfc4122()] = true;
                    }
                }
                // Also refresh child-birth events for previously linked parents after a child's date changed.
                foreach ($this->parents->findForChildren($tree, array_keys($affected)) as $link) {
                    $this->events->synchronizeParentLink($link, $now);
                }
                foreach ($newMarriages as [$marriage, $maleSurname, $femaleSurname]) {
                    $this->events->synchronizeMarriage($marriage, $maleSurname, $femaleSurname, $now);
                }
                $this->entityManager->flush();
                foreach ($this->operations->events($tree, $changes, $projection, $managed) as $id) {
                    $affected[$id] = true;
                }
                if ($projection->hasChanges) {
                    $tree->touch($now);
                    $this->entityManager->flush();
                    $this->layouts->invalidateChanged($tree);
                }

                return [
                    'personIds'         => $ids,
                    'affectedPersonIds' => array_keys($affected),
                    'newPersonIds'      => $newIds,
                    'etag'              => $this->versions->etag($tree),
                ];
            },
        );
        if (null === $result) {
            throw new ApiException(412, 'TREE_VERSION_CONFLICT');
        }
        $this->media->deleteStored($removedMedia);

        return $result;
    }
}
