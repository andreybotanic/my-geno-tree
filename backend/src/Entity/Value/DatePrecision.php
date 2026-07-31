<?php

namespace App\Entity\Value;

enum DatePrecision: string
{
    case DAY = 'day';
    case MONTH = 'month';
    case YEAR = 'year';
}
