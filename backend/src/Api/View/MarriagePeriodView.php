<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
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
        #[Map(transform: new MapObject(GenealogicalDateView::class))] public GenealogicalDateView $marriageDate,
        #[Map(transform: new MapObject(GenealogicalDateView::class))] public ?GenealogicalDateView $dissolutionDate,
        #[Map(transform: new MapObject(EventPlaceView::class))] public ?EventPlaceView $marriagePlace,
        #[Map(transform: new MapObject(EventPlaceView::class))] public ?EventPlaceView $dissolutionPlace,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
