<?php

namespace App\Application\Tree\ChangeSet;

use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Tree\Layout\TreeLayoutInvalidator;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
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
    ) {
    }

    /**
     * May participate in an outer application transaction to save a workflow receipt atomically.
     *
     * @return array{personIds: array<string, string>, affectedPersonIds: list<string>, etag: string}
     */
    public function execute(FamilyTree $tree, TreeChangeSet $changes, string $ifMatch): array
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($tree, $changes, $ifMatch): array {
                $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($tree);
                $this->versions->assertMatches($tree, $ifMatch);
                $now = $this->clock->now();
                $projection = $this->projector->project($tree, $changes, $now, true);
                $managed = $projection->originals;
                $ids = $affected = [];
                foreach ($changes->people as $change) {
                    $projected = $projection->people[$change->reference];
                    $id = $projected->getId()->toRfc4122();
                    if (null === $change->existingPersonId) {
                        $person = $projected;
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
                    $newMarriages[] = $new;
                    $affected[$m] = $affected[$f] = true;
                }
                $this->entityManager->flush();
                // Also refresh child-birth events for previously linked parents after a child's date changed.
                foreach ($this->parents->findForChildren($tree, array_keys($affected)) as $link) {
                    $this->events->synchronizeParentLink($link, $now);
                }
                foreach ($newMarriages as $marriage) {
                    $this->events->synchronizeMarriage($marriage, null, null, $now);
                }
                if ([] !== $affected) {
                    $tree->touch($now);
                    $this->entityManager->flush();
                    $this->layouts->invalidateChanged($tree);
                }

                return ['personIds' => $ids, 'affectedPersonIds' => array_keys($affected), 'etag' => $this->versions->etag($tree)];
            }
        );
    }
}
