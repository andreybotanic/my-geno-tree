<?php

namespace App\Api\View;

use App\Entity\EventPlace;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: EventPlace::class)]
final readonly class CoordinatesView
{
    public function __construct(public float $latitude, public float $longitude)
    {
    }
}
