<?php

declare(strict_types=1);

namespace App\Model;

/**
 * A yearless calendar date (day + month), as stored by a SettingType::DayMonth setting in its
 * canonical "MM-DD" form (e.g. "09-01" for September 1st). Only combinations that exist every
 * year are valid: February 29th is rejected, so the date never has to be moved around in a
 * non-leap year.
 */
final readonly class DayMonth
{
    private function __construct(
        public int $month,
        public int $day,
    ) {}

    /** Parses a canonical "MM-DD" value; null if malformed or not a date that exists every year. */
    public static function tryParse(mixed $value): ?self
    {
        if (!is_string($value) || preg_match('/^(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        $month = (int) $m[1];
        $day   = (int) $m[2];

        // 2001 is a non-leap year: checkdate() there accepts exactly the dates every year has.
        return checkdate($month, $day, 2001) ? new self($month, $day) : null;
    }

    public function toString(): string
    {
        return \sprintf('%02d-%02d', $this->month, $this->day);
    }
}
