<?php

namespace App\Entity;

enum MediaVariantType: string
{
    case AVATAR = 'avatar';
    case FULL = 'full';
    case PORTRAIT = 'portrait';
    case THUMBNAIL = 'thumbnail';
}
