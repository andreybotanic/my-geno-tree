<?php

namespace App\Entity\Value;

enum EventSource: string
{
    case AUTOMATIC = 'automatic';
    case MANUAL = 'manual';
}
