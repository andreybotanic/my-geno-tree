<?php

namespace App\Api\View;

use App\Entity\Value\KnownText;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

#[Map(source: KnownText::class)]
#[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
final readonly class KnownTextView
{
    public function __construct(
        #[Map(source: 'known', transform: [self::class, 'state'])] public string $state,
        public ?string $value,
    ) {
    }

    public static function state(bool $known): string
    {
        return $known ? 'known' : 'unknown';
    }
}
