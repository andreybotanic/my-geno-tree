<?php

namespace App\Application\Person;

use App\Entity\Value\Gender;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface LocalizedSurnameLocalizer
{
    public function supports(string $locale): bool;

    public function localize(
        string $surname,
        Gender $sourceGender,
        Gender $targetGender,
    ): string;
}
