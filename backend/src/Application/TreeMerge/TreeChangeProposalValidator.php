<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Api\PersonViewFactory;
use App\Application\Tree\ChangeSet\TreeChangeSetFactory;
use App\Application\Tree\ChangeSet\TreeChangeSetProjector;
use App\Entity\FamilyTree;
use Symfony\Component\Clock\ClockInterface;

final readonly class TreeChangeProposalValidator
{
    public function __construct(
        private TreeChangeSetFactory $factory,
        private TreeChangeSetProjector $projector,
        private TreeMergeProposalValidator $evidence,
        private PersonViewFactory $views,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $value
     * @param list<array{id: string, role: string, content: string}> $sources
     * @param array<string, mixed>|null                              $saved
     */
    public function validate(
        FamilyTree $tree,
        array $value,
        array $sources,
        ?array $saved,
        bool $manual,
    ): ValidatedTreeMergeProposal {
        // Stored review groups are projections; operations remain the authoritative editable input.
        $allowed = ['operations', 'people', 'parents', 'marriages', 'questions', 'operationSubjects'];
        if ([] !== array_diff(array_keys($value), $allowed) || !is_array($value['operations'] ?? null)
            || !array_is_list($value['operations'])) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID');
        }
        foreach ($value['operations'] as $index => $operation) {
            if (!is_array($operation)) {
                throw new ApiException(422, 'TREE_CHANGE_INVALID');
            }
            $this->evidence->evidence(
                $sources,
                $saved,
                $operation['evidence'] ?? [],
                $manual,
                'operations[' . $index . '].evidence',
            );
        }
        $changes = $this->factory->create($tree, $value['operations']);
        $projection = $this->projector->project($tree, $changes, $this->clock->now());
        $people = $parents = $marriages = [];
        foreach ($changes->people as $change) {
            $person = $projection->people[$change->reference];
            $view = $this->views->create($person);
            $people[] = [
                'id'               => $change->reference,
                'existingPersonId' => $change->existingPersonId,
                'resolution'       => null === $change->existingPersonId ? 'new' : 'existing',
                'fields'           => array_intersect_key(
                    $view,
                    array_flip(
                        [
                            'birthSurname',
                            'givenName',
                            'patronymic',
                            'gender',
                            'birthDate',
                            'deathDate',
                        ],
                    ),
                ),
                'lifeStatus' => null === $person->getDeathDate() ? 'alive' : 'deceased',
                'evidence'   => [],
                'before'     => null === $change->existingPersonId ? null
                    : $this->views->create($projection->originals[$change->existingPersonId]),
            ];
        }
        foreach ($changes->parents as $change) {
            $parents[] = [
                'parent'   => $change->parent,
                'child'    => $change->child,
                'role'     => $change->role->value,
                'evidence' => [],
            ];
        }
        foreach ($changes->operations as $operation) {
            if ('create_marriage_period' === $operation->type) {
                $fields = $operation->object('fields');
                $marriages[] = [
                    'first'           => $operation->string('first'),
                    'second'          => $operation->string('second'),
                    'marriageDate'    => $fields['marriageDate'],
                    'dissolutionDate' => $fields['dissolutionDate'] ?? null,
                    'newPeriod'       => $operation->boolean('newPeriod'),
                    'evidence'        => $operation->arguments['evidence'] ?? [],
                ];
            }
        }
        $subjects = [];
        foreach ($changes->operations as $operation) {
            $refs = [];
            foreach (['personId', 'parent', 'child', 'first', 'second'] as $key) {
                if (isset($operation->arguments[$key])) {
                    $refs[] = $operation->string($key);
                }
            }
            if ('create_person' === $operation->type) {
                $refs[] = $operation->string('id');
            }
            if ('create_events' === $operation->type) {
                $refs = $operation->strings('personIds');
            }
            $labels = [];
            if (isset($operation->arguments['eventId'])) {
                $event = $projection->events[$operation->string('eventId')];
                $refs[] = $event->getPerson()->getId()->toRfc4122();
                if (null !== $event->getTitle()) {
                    $labels[] = $event->getTitle();
                }
            }
            foreach ($projection->removedParents as $link) {
                if (($operation->arguments['parentLinkId'] ?? null) === $link->getId()->toRfc4122()) {
                    $refs[] = $link->getParent()->getId()->toRfc4122();
                    $refs[] = $link->getChild()->getId()->toRfc4122();
                }
            }
            foreach ([...$projection->removedMarriages, ...$projection->updatedMarriages] as $marriage) {
                if (($operation->arguments['marriageId'] ?? null) === $marriage->getId()->toRfc4122()
                    || (
                        'update_marriage_period' === $operation->type
                        && ($projection->updatedMarriages[$operation->string('marriageId')] ?? null) === $marriage
                    )) {
                    $refs[] = $marriage->getMalePerson()->getId()->toRfc4122();
                    $refs[] = $marriage->getFemalePerson()->getId()->toRfc4122();
                }
            }
            foreach (array_unique($refs) as $ref) {
                $person = $projection->people[$ref] ?? $projection->originals[$ref] ?? null;
                if (null !== $person) {
                    $labels[] = implode(
                        ' ',
                        array_filter(
                            [
                                $person->getBirthSurname()->getValue(),
                                $person->getGivenName()->getValue(),
                                $person->getPatronymic()->getValue(),
                            ],
                        ),
                    );
                }
            }
            $subjects[] = $labels;
        }

        return new ValidatedTreeMergeProposal(
            [
                'people'            => $people,
                'parents'           => $parents,
                'marriages'         => $marriages,
                'questions'         => [],
                'operations'        => $value['operations'],
                'operationSubjects' => $subjects,
            ],
            [],
            $changes,
            $projection->hasChanges,
        );
    }
}
