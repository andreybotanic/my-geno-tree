<?php

namespace App\Api\View;

use App\Entity\Value\GenealogicalDate;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[Map(source: GenealogicalDate::class)]
#[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
final readonly class DateEndView
{
    public function __construct(
        #[Map(source: 'endYear')] public int $year,
        #[Map(source: 'endMonth')] public ?int $month,
        #[Map(source: 'endDay')] public ?int $day,
    ) {
    }
}
