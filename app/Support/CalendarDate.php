<?php

declare(strict_types=1);

namespace App\Support;

final class CalendarDate
{
    /** @phpstan-assert-if-true non-empty-string $date */
    public static function isCanonical(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
