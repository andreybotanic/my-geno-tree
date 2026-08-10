<?php

namespace App\Entity\Value;

enum EventType: string
{
    case BIRTH = 'birth';
    case CHILD_BIRTH = 'child_birth';
    case CUSTOM = 'custom';
    case DEATH = 'death';
    case DIVORCE = 'divorce';
    case MARRIAGE = 'marriage';
    case RESIDENCE = 'residence';
    case SURNAME_CHANGE = 'surname_change';
}
