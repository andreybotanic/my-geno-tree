<?php

namespace App\Api\View;

use App\Api\Mapping\MapObject;
use App\Entity\Value\DateKind;
use App\Entity\Value\GenealogicalDate;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[Map(source: GenealogicalDate::class)]
#[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
final readonly class GenealogicalDateView
{
    public function __construct(
        #[Map(source: 'known', transform: [KnownTextView::class, 'state'])] public string $state,
        public ?DateKind $kind,
        public ?int $year,
        public ?int $month,
        public ?int $day,
        #[Map(source: 'kind', transform: [[self::class, 'end'], new MapObject(DateEndView::class)])]
        public ?DateEndView $end,
    ) {
    }

    public static function end(mixed $kind, object $date): ?GenealogicalDate
    {
        assert($date instanceof GenealogicalDate);

        return DateKind::BETWEEN === $kind ? $date : null;
    }
}
