<?php

namespace App\Api\View;

use App\Entity\MarriagePeriod;
use App\Entity\Person;
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
        #[Map(source: 'kind', transform: [KnownTextView::class, 'state'])] public string $state,
        public ?DateKind $kind,
        public ?int $year,
        public ?int $month,
        public ?int $day,
        #[Map(source: 'kind', transform: [self::class, 'end'])]
        public ?DateEndView $end,
    ) {
    }

    public static function end(mixed $kind, object $date): ?GenealogicalDate
    {
        assert($date instanceof GenealogicalDate);

        return DateKind::BETWEEN === $kind ? $date : null;
    }

    public static function ended(mixed $value, Person|MarriagePeriod $source): ?GenealogicalDate
    {
        // These computed getters have no backing property; the mapping runs from the readable entity ID.
        return $source instanceof Person ? $source->getDeathDate() : $source->getDissolutionDate();
    }
}
