<?php

namespace App\Entity\Value;

enum Gender: string
{
    case FEMALE = 'female';
    case MALE = 'male';
    case UNKNOWN = 'unknown';
}
