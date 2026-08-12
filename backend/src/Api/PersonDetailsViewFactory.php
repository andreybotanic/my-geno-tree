<?php

namespace App\Api;

use App\Application\Event\EventTimelineService;
use App\Application\Tree\GraphInvariantPolicy;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;

final readonly class PersonDetailsViewFactory
{
    public function __construct(
        private PersonRepository $people,
        private ParentLinkRepository $parentLinks,
        private MarriagePeriodRepository $marriages,
        private PersonViewFactory $personViews,
        private KnownTextViewFactory $texts,
        private GenealogicalDateViewFactory $dates,
        private ParentLinkViewFactory $parentLinkViews,
        private MarriagePeriodViewFactory $marriageViews,
        private EventTimelineService $timeline,
        private EventViewFactory $eventViews,
        private GraphInvariantPolicy $invariants,
        private TreeVersion $versions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(FamilyTree $tree, Person $person): array
    {
        $links = $this->parentLinks->findRelationshipContext($tree, $person);
        $personId = $person->getId()->toRfc4122();
        $marriages = $this->marriages->findForPerson($person);
        usort($links, static fn (ParentLink $left, ParentLink $right): int => (
            $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122()
        ));
        usort($marriages, static fn (MarriagePeriod $left, MarriagePeriod $right): int => (
            $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122()
        ));

        $personIds = [$personId => true];
        foreach ($links as $link) {
            $personIds[$link->getParent()->getId()->toRfc4122()] = true;
            $personIds[$link->getChild()->getId()->toRfc4122()] = true;
        }
        foreach ($marriages as $marriage) {
            $personIds[$marriage->getMalePerson()->getId()->toRfc4122()] = true;
            $personIds[$marriage->getFemalePerson()->getId()->toRfc4122()] = true;
        }
        $relatedPeople = [];
        foreach ($this->people->findByTreeAndIds($tree, array_keys($personIds)) as $candidate) {
            $candidateId = $candidate->getId()->toRfc4122();
            if ($candidateId !== $personId) {
                $relatedPeople[$candidateId] = $candidate;
            }
        }
        ksort($relatedPeople, SORT_STRING);

        return [
            'etag'          => $this->versions->etag($tree),
            'person'        => $this->personViews->create($person),
            'relationships' => [
                'people'      => array_map($this->relationshipPerson(...), array_values($relatedPeople)),
                'parentLinks' => array_map($this->parentLinkViews->create(...), $links),
                'marriages'   => array_map($this->marriageViews->create(...), $marriages),
            ],
            'events' => array_map(
                $this->eventViews->create(...),
                $this->timeline->forPerson($person),
            ),
            'permissions' => [
                'canEdit'                => true,
                'canDelete'              => $this->invariants->canDeletePerson($tree, $person),
                'removableParentLinkIds' => array_values(array_map(
                    static fn (ParentLink $link): string => $link->getId()->toRfc4122(),
                    array_filter($links, fn (ParentLink $link): bool => $this->invariants->canRemoveParentLink($tree, $link)
                    ),
                )),
                'removableMarriageIds' => array_values(array_map(
                    static fn (MarriagePeriod $marriage): string => $marriage->getId()->toRfc4122(),
                    array_filter($marriages, fn (MarriagePeriod $marriage): bool => $this->invariants->canRemoveMarriage($tree, $marriage)
                    ),
                )),
            ],
        ];
    }

    /**
     * @return array{id: string, birthSurname: array<string, mixed>, givenName: array<string, mixed>, patronymic: array<string, mixed>, gender: string, birthDate: array<string, mixed>, deathDate: array<string, mixed>|null}
     */
    private function relationshipPerson(Person $person): array
    {
        $deathDate = $person->getDeathDate();

        return [
            'id'           => $person->getId()->toRfc4122(),
            'birthSurname' => $this->texts->create($person->getBirthSurname()),
            'givenName'    => $this->texts->create($person->getGivenName()),
            'patronymic'   => $this->texts->create($person->getPatronymic()),
            'gender'       => $person->getGender()->value,
            'birthDate'    => $this->dates->create($person->getBirthDate()),
            'deathDate'    => null === $deathDate ? null : $this->dates->create($deathDate),
        ];
    }
}
