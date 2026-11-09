<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
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
        #[Map(transform: new MapObject(KnownTextView::class))] public KnownTextView $birthSurname,
        #[Map(transform: new MapObject(KnownTextView::class))] public KnownTextView $givenName,
        #[Map(transform: new MapObject(KnownTextView::class))] public KnownTextView $patronymic,
        public Gender $gender,
        #[Map(transform: new MapObject(GenealogicalDateView::class))] public GenealogicalDateView $birthDate,
        #[Map(transform: new MapObject(GenealogicalDateView::class))] public ?GenealogicalDateView $deathDate,
        #[Map(transform: new MapObject(EventPlaceView::class))] public ?EventPlaceView $birthPlace,
        #[Map(transform: new MapObject(EventPlaceView::class))] public ?EventPlaceView $deathPlace,
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
