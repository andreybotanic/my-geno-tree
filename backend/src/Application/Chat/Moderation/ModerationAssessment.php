<?php

namespace App\Application\Chat\Moderation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class ModerationAssessment
{
    public function __construct(#[Assert\Range(min: 0, max: 1)] public float $risk)
    {
    }
}
