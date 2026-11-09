<?php

namespace App\Api\View;

use App\Entity\Person;
use App\Entity\Value\Gender;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: Person::class)]
final readonly class RelationshipPersonView
{
    public function __construct(
        public Uuid $id,
        public KnownTextView $birthSurname,
        public KnownTextView $givenName,
        public KnownTextView $patronymic,
        public Gender $gender,
        public GenealogicalDateView $birthDate,
        #[Map(source: 'id', transform: [GenealogicalDateView::class, 'ended'])]
        public ?GenealogicalDateView $deathDate,
        #[Map(source: 'photoAttachment', transform: [PersonView::class, 'photoThumbnailUrl'])]
        public ?string $photoThumbnailUrl,
    ) {
    }
}
