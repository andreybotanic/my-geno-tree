<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
use App\Entity\Person;
use App\Entity\Value\Gender;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: Person::class)]
final readonly class RelationshipPersonView
{
    public function __construct(
        public Uuid $id,
        #[Map(transform: new MapObject(KnownTextView::class))] public KnownTextView $birthSurname,
        #[Map(transform: new MapObject(KnownTextView::class))] public KnownTextView $givenName,
        #[Map(transform: new MapObject(KnownTextView::class))] public KnownTextView $patronymic,
        public Gender $gender,
        #[Map(transform: new MapObject(GenealogicalDateView::class))] public GenealogicalDateView $birthDate,
        #[Map(transform: new MapObject(GenealogicalDateView::class))] public ?GenealogicalDateView $deathDate,
        #[Map(source: 'photoAttachment', transform: [PersonView::class, 'photoThumbnailUrl'])]
        public ?string $photoThumbnailUrl,
    ) {
    }
}
