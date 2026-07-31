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

        $precision = $date->getPrecision();
        $year = $date->getYear();
        if (null === $precision || null === $year) {
            throw new \LogicException('Known genealogical dates require precision and year.');
        }

        $view = [
            'state'       => 'known',
            'precision'   => $precision->value,
            'year'        => $year,
            'approximate' => $date->isApproximate(),
        ];
        if (DatePrecision::YEAR !== $precision) {
            $month = $date->getMonth();
            if (null === $month) {
                throw new \LogicException('Month and day precision require a month.');
            }

            $view['month'] = $month;
        }
        if (DatePrecision::DAY === $precision) {
            $day = $date->getDay();
            if (null === $day) {
                throw new \LogicException('Day precision requires a day.');
            }

            $view['day'] = $day;
        }

        return $view;
    }
}
