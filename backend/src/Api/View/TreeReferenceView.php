<?php

namespace App\Api\View;

use App\Entity\FamilyTree;
use App\Entity\Person;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;

#[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
final readonly class TreeReferenceView
{
    /** @param list<Uuid>|null $personIds */
    public function __construct(public string $type, public ?Uuid $personId, public ?array $personIds)
    {
    }

    public static function reference(?Person $primary, FamilyTree $tree): ?self
    {
        if (null === $primary) {
            return null;
        }
        $secondary = $tree->getReferenceSecondaryPerson();

        return null === $secondary
            ? new self('person', $primary->getId(), null)
            : new self('marriage', null, [$primary->getId(), $secondary->getId()]);
    }
}
