<?php

namespace App\Application\Person;

use App\Entity\Value\Gender;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface LocalizedPatronymicLocalizer
{
    public function supports(string $locale): bool;

    public function determine(string $fatherGivenName, Gender $childGender): ?string;
}
