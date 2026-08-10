<?php

namespace App\Api;

use App\Entity\Value\DateKind;
use App\Entity\Value\GenealogicalDate;

final readonly class GenealogicalDateViewFactory
{
    /**
     * @return array<string, array<string, int>|int|string>
     */
    public function create(GenealogicalDate $date): array
    {
        if (!$date->isKnown()) {
            return ['state' => 'unknown'];
        }

        $kind = $date->getKind();
        $year = $date->getYear();
        if (null === $kind || null === $year) {
            throw new \LogicException('Known genealogical dates require kind and year.');
        }

        $view = [
            'state' => 'known',
            'kind'  => $kind->value,
            'year'  => $year,
        ];
        if (null !== $date->getMonth()) {
            $month = $date->getMonth();
            $view['month'] = $month;
        }
        if (null !== $date->getDay()) {
            $day = $date->getDay();
            $view['day'] = $day;
        }
        if (DateKind::BETWEEN === $kind) {
            $endYear = $date->getEndYear();
            if (null === $endYear) {
                throw new \LogicException('Between dates require an end date.');
            }
            $end = ['year' => $endYear];
            if (null !== $date->getEndMonth()) {
                $end['month'] = $date->getEndMonth();
            }
            if (null !== $date->getEndDay()) {
                $end['day'] = $date->getEndDay();
            }
            $view['end'] = $end;
        }

        return $view;
    }
}
