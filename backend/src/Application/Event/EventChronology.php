<?php

namespace App\Application\Event;

use App\Entity\Value\GenealogicalDate;

final readonly class EventChronology
{
    public function compare(
        GenealogicalDate $left,
        GenealogicalDate $right,
    ): int {
        $leftLatest = $left->latest();
        $rightEarliest = $right->earliest();
        if (
            null !== $leftLatest
            && null !== $rightEarliest
            && $leftLatest < $rightEarliest
        ) {
            return -1;
        }

        $rightLatest = $right->latest();
        $leftEarliest = $left->earliest();
        if (
            null !== $rightLatest
            && null !== $leftEarliest
            && $rightLatest < $leftEarliest
        ) {
            return 1;
        }

        return 0;
    }

    public function isAmbiguous(
        GenealogicalDate $left,
        GenealogicalDate $right,
    ): bool {
        return 0 === $this->compare($left, $right);
    }
}
