<?php

namespace App\Entity\Value;

enum DateKind: string
{
    case EXACT = 'exact';
    case APPROXIMATE = 'approximate';
    case BEFORE = 'before';
    case AFTER = 'after';
    case BETWEEN = 'between';
}
