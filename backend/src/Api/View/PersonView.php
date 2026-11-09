<?php

namespace App\Api\View;

use App\Entity\MediaAttachment;
use App\Entity\Person;
use App\Entity\Value\Gender;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: Person::class)]
final readonly class PersonView
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
        public ?EventPlaceView $birthPlace,
        public ?EventPlaceView $deathPlace,
        #[Map(source: 'photoAttachment', transform: [self::class, 'photoThumbnailUrl'])]
        public ?string $photoThumbnailUrl,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function photoThumbnailUrl(?MediaAttachment $attachment, Person $person): ?string
    {
        return null === $attachment ? null : sprintf(
            '/trees/%s/people/%s/photo/thumbnail?media=%s',
            $person->getTree()->getId(),
            $person->getId(),
            $attachment->getMedia()->getId(),
        );
    }
}
