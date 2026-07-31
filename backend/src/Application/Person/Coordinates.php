<?php

namespace App\Application\Person;

final readonly class Coordinates
{
    public function __construct(public float $x, public float $y)
    {
    }
}
