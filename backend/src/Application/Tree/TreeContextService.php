<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Api\GenealogicalDateViewFactory;
use App\Api\KnownTextViewFactory;
use App\Application\Person\PersonSearchService;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Repository\FamilyTreeRepository;
use App\Repository\MarriagePeriodRepository;
use App\Repository\ParentLinkRepository;
use App\Repository\PersonRepository;
use App\Security\FamilyTreeVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class TreeContextService
{
    public function __construct(
        private FamilyTreeRepository $trees,
        private PersonRepository $people,
        private ParentLinkRepository $parents,
        private MarriagePeriodRepository $marriages,
        private PersonSearchService $search,
        private TreeVersion $versions,
        private AuthorizationCheckerInterface $authorization,
        private KnownTextViewFactory $texts,
        private GenealogicalDateViewFactory $dates,
    ) {
    }

    public function tree(string $id, bool $edit = false): FamilyTree
    {
        $tree = Uuid::isValid($id) ? $this->trees->find($id) : null;
        if (null === $tree || !$this->authorization->isGranted($edit ? FamilyTreeVoter::EDIT : FamilyTreeVoter::VIEW, $tree)) {
            throw new ApiException(404, 'TREE_NOT_FOUND');
        }

        return $tree;
    }

    /** @return array<string, mixed> */
    public function context(string $treeId, ?string $referencePersonId): array
    {
        $tree = $this->tree($treeId);
        $context = ['treeId'    => $treeId, 'etag' => $this->versions->etag($tree), 'personCount' => $this->people->countForTree($tree),
            'referencePersonId' => $referencePersonId, 'people' => [], 'parents' => [], 'marriages' => [], 'truncated' => false];
        if (null === $referencePersonId) {
            return $context;
        }
        $person = Uuid::isValid($referencePersonId) ? $this->people->findInTree($tree, $referencePersonId) : null;
        if (null === $person) {
            throw new ApiException(404, 'PERSON_NOT_FOUND');
        }
        $parents = $this->parents->findBoundedContext($tree, $person);
        $marriages = $this->marriages->findBoundedContext($tree, $person);
        $context['truncated'] = count($parents) > 10 || count($marriages) > 10;
        $context['parents'] = array_slice($parents, 0, 10);
        $context['marriages'] = array_slice($marriages, 0, 10);
        $ids = [$referencePersonId];
        foreach ($context['parents'] as $link) {
            $ids[] = (string)$link['parentId'];
            $ids[] = (string)$link['childId'];
        }
        foreach ($context['marriages'] as $link) {
            $ids[] = (string)$link['first'];
            $ids[] = (string)$link['second'];
        }
        $context['people'] = array_map($this->person(...), $this->people->findRelationshipPeopleByTreeAndIds($tree, array_values(array_unique($ids))));

        return $context;
    }

    /** @return list<array{id: string, fullName: string, birthYear: ?int, deathYear: ?int}> */
    public function find(string $treeId, string $query): array
    {
        return array_slice($this->search->search($this->tree($treeId), $query), 0, 5);
    }

    /** @return array<string, mixed> */
    private function person(Person $person): array
    {
        return ['id'       => $person->getId()->toRfc4122(),
            'givenName'    => $this->texts->create($person->getGivenName()),
            'birthSurname' => $this->texts->create($person->getBirthSurname()),
            'patronymic'   => $this->texts->create($person->getPatronymic()),
            'gender'       => $person->getGender()->value, 'birthDate' => $this->dates->create($person->getBirthDate()),
            'deathDate'    => null === $person->getDeathDate() ? null : $this->dates->create($person->getDeathDate())];
    }
}
