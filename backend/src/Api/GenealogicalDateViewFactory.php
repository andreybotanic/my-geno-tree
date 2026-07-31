<?php

namespace App\Api;

use App\Entity\Value\DatePrecision;
use App\Entity\Value\GenealogicalDate;

final readonly class GenealogicalDateViewFactory
{
    /**
     * @return array<string, bool|int|string>
     */
    public function create(GenealogicalDate $date): array
    {
        if (!$date->isKnown()) {
            return ['state' => 'unknown'];
        }

        $view = [
            'state' => 'known',
            'precision' => $date->getPrecision()?->value,
            'year' => $date->getYear(),
            'approximate' => $date->isApproximate(),
        ];
        if (DatePrecision::YEAR !== $date->getPrecision()) {
            $view['month'] = $date->getMonth();
        }
        if (DatePrecision::DAY === $date->getPrecision()) {
            $view['day'] = $date->getDay();
        }

        /** @var array<string, bool|int|string> $view */
        return $view;
    }
}
