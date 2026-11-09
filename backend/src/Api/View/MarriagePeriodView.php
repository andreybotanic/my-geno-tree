<?php

namespace App\Api\View;

use App\Entity\MarriagePeriod;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: MarriagePeriod::class)]
final readonly class MarriagePeriodView
{
    public function __construct(
        public Uuid $id,
        #[Map(source: 'malePerson.id')] public Uuid $malePersonId,
        #[Map(source: 'femalePerson.id')] public Uuid $femalePersonId,
        public GenealogicalDateView $marriageDate,
        #[Map(source: 'id', transform: [GenealogicalDateView::class, 'ended'])]
        public ?GenealogicalDateView $dissolutionDate,
        public ?EventPlaceView $marriagePlace,
        public ?EventPlaceView $dissolutionPlace,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
