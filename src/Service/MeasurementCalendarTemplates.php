<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;

/**
 * The starting points for a measurement calendar: by evaluation (1.ª, 2.ª, 3.ª, Final 1 and
 * Final 2), by term, monthly (September to June) or the whole year at once. Their dates are only
 * a guess from the year's name ("2026-2027": September 2026 to June 2027) — each centre adjusts
 * them to its own calendar.
 */
final class MeasurementCalendarTemplates
{
    public const array KEYS = ['evaluations', 'terms', 'monthly', 'year'];

    private const array MONTHS = [9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre', 1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio'];

    /**
     * @return list<array{name: string, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function periods(string $key, AcademicYear $year, \DateTimeImmutable $today): array
    {
        $first = self::firstYear($year, $today);
        $d     = static fn (int $y, int $m, int $day): \DateTimeImmutable => (new \DateTimeImmutable())->setDate($y, $m, $day)->setTime(0, 0);
        $next  = $first + 1;

        return match ($key) {
            'evaluations' => [
                ['name' => '1.ª evaluación', 'start' => $d($first, 9, 15), 'end' => $d($first, 12, 22)],
                ['name' => '2.ª evaluación', 'start' => $d($next, 1, 8), 'end' => $d($next, 3, 27)],
                ['name' => '3.ª evaluación', 'start' => $d($next, 4, 6), 'end' => $d($next, 6, 22)],
                ['name' => 'Final 1', 'start' => $d($next, 6, 22), 'end' => $d($next, 6, 25)],
                ['name' => 'Final 2', 'start' => $d($next, 6, 25), 'end' => $d($next, 6, 30)],
            ],
            'terms' => [
                ['name' => '1.er trimestre', 'start' => $d($first, 9, 15), 'end' => $d($first, 12, 22)],
                ['name' => '2.º trimestre', 'start' => $d($next, 1, 8), 'end' => $d($next, 3, 27)],
                ['name' => '3.er trimestre', 'start' => $d($next, 4, 6), 'end' => $d($next, 6, 22)],
            ],
            'monthly' => array_map(
                static function (int $month) use ($first, $next, $d): array {
                    $start = $d($month >= 9 ? $first : $next, $month, 1);

                    return ['name' => self::MONTHS[$month], 'start' => $start, 'end' => $start->modify('last day of this month')];
                },
                array_keys(self::MONTHS),
            ),
            'year' => [
                // No year in the name: copied into the next year, it has to keep reading right.
                ['name' => 'Curso completo', 'start' => $d($first, 9, 15), 'end' => $d($next, 6, 30)],
            ],
            default => throw new \InvalidArgumentException(\sprintf('Unknown measurement calendar template "%s".', $key)),
        };
    }

    /** The calendar year the academic year starts in: the first four-digit number in its name, or else this one's. */
    public static function firstYear(AcademicYear $year, \DateTimeImmutable $today): int
    {
        if (preg_match('/\b(\d{4})\b/', $year->getName(), $m) === 1) {
            return (int) $m[1];
        }

        $current = (int) $today->format('Y');

        return (int) $today->format('n') >= 9 ? $current : $current - 1;
    }
}
