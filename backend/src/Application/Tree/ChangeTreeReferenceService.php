<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Application\Person\PersonLookup;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Repository\MarriagePeriodRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeTreeReferenceService
{
    public function __construct(
        private PersonLookup $people,
        private MarriagePeriodRepository $marriages,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function change(FamilyTree $tree, array $payload, ?string $ifMatch): void
    {
        $this->entityManager->wrapInTransaction(function () use ($tree, $payload, $ifMatch): void {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($tree);
            $this->versions->assertMatches($tree, $ifMatch);

            $type = $payload['type'] ?? null;
            if ('person' === $type) {
                $this->assertExactKeys($payload, ['type', 'personId']);
                $person = $this->person($tree, $payload['personId'], 'personId');
                if (
                    $tree->getReferencePrimaryPerson() === $person
                    && null === $tree->getReferenceSecondaryPerson()
                ) {
                    return;
                }
                $tree->referencePerson($person);
            } elseif ('marriage' === $type) {
                $this->assertExactKeys($payload, ['type', 'personIds']);
                [$first, $second] = $this->marriagePeople($tree, $payload['personIds']);
                if (
                    $tree->getReferencePrimaryPerson() === $first
                    && $tree->getReferenceSecondaryPerson() === $second
                ) {
                    return;
                }
                $tree->referenceMarriage($first, $second);
            } else {
                $this->invalid('type', 'reference_type_invalid');
            }

            $tree->touch($this->clock->now());
        });
    }

    /** @return array{Person, Person} */
    private function marriagePeople(FamilyTree $tree, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || 2 !== count($value)) {
            $this->invalid('personIds', 'two_person_ids_required');
        }
        $first = $this->person($tree, $value[0], 'personIds.0');
        $second = $this->person($tree, $value[1], 'personIds.1');
        if ($first === $second) {
            $this->invalid('personIds', 'people_must_be_distinct');
        }
        if (0 === $this->marriages->countForPair($first, $second)) {
            $this->invalid('personIds', 'marriage_required');
        }

        return $first->getId()->toRfc4122() < $second->getId()->toRfc4122()
            ? [$first, $second]
            : [$second, $first];
    }

    private function person(FamilyTree $tree, mixed $value, string $field): Person
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            $this->invalid($field, 'uuid_required');
        }

        return $this->people->require($tree, $value);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $expected
     */
    private function assertExactKeys(array $payload, array $expected): void
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $expected, true)) {
                $this->invalid($key, 'field_not_allowed');
            }
        }
        foreach ($expected as $key) {
            if (!array_key_exists($key, $payload)) {
                $this->invalid($key, 'field_required');
            }
        }
    }

    private function invalid(string $field, string $error): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'TREE_REFERENCE_INVALID', 'tree_reference_invalid', [$field => [$error]]);
    }
}
