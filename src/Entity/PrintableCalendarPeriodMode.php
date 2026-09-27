<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How a PrintableCalendarPeriod's date range is defined: a plain range, or one end plus a weekly
 * hours pattern (Monday-Friday) that the generator walks day by day to find the other end.
 */
enum PrintableCalendarPeriodMode: string
{
    case DateRange      = 'date_range';
    case StartWithHours = 'start_with_hours';
    case EndWithHours   = 'end_with_hours';

    public function requiresHours(): bool
    {
        return $this !== self::DateRange;
    }
}
