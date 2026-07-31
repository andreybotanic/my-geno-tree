<?php

namespace App\Entity\Value;

enum ParentRole: string
{
    case FATHER = 'father';
    case MOTHER = 'mother';

    public function requiredGender(): Gender
    {
        return match ($this) {
            self::FATHER => Gender::MALE,
            self::MOTHER => Gender::FEMALE,
        };
    }
}
