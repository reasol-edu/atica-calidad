<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\NonWorkingDayRepository;

final class NonWorkingDayChecker
{
    /** @var array<string, array<string, ?string>> academic year (uuid) => [ISO date => description] */
    private array $mapCache = [];

    public function __construct(
        private readonly NonWorkingDayRepository $nonWorkingDays,
    ) {
    }

    public function isWeekend(\DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') >= 6;
    }

    public function isNonWorkingDay(AcademicYear $year, \DateTimeImmutable $date): bool
    {
        return $this->isWeekend($date) || array_key_exists($date->format('Y-m-d'), $this->nonWorkingDayMap($year));
    }

    public function descriptionFor(AcademicYear $year, \DateTimeImmutable $date): ?string
    {
        return $this->nonWorkingDayMap($year)[$date->format('Y-m-d')] ?? null;
    }

    public function countSchoolDays(AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $holidays = $this->nonWorkingDayMap($year);

        $count  = 0;
        $cursor = $from;
        while ($cursor <= $to) {
            if (!$this->isWeekend($cursor) && !array_key_exists($cursor->format('Y-m-d'), $holidays)) {
                ++$count;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }

    public function addSchoolDays(AcademicYear $year, \DateTimeImmutable $from, int $schoolDays): \DateTimeImmutable
    {
        $holidays = $this->nonWorkingDayMap($year);

        $remaining = $schoolDays;
        $cursor    = $from;
        while (true) {
            if (!$this->isWeekend($cursor) && !array_key_exists($cursor->format('Y-m-d'), $holidays)) {
                --$remaining;
                if ($remaining <= 0) {
                    return $cursor;
                }
            }
            $cursor = $cursor->modify('+1 day');
        }
    }

    /**
     * ISO dates (Y-m-d) of the academic year's declared holidays, to inject into
     * the Stimulus controllers that block selection of non-school dates.
     *
     * @return list<string>
     */
    public function datesFor(AcademicYear $year): array
    {
        return array_keys($this->nonWorkingDayMap($year));
    }

    /**
     * Non-working dates registered for the academic year, loaded in a single query and
     * memoized per academic year for the rest of the request: avoids the N+1
     * that querying the database for every visible calendar day would cause.
     *
     * @return array<string, ?string> ISO date (Y-m-d) => description (or null)
     */
    private function nonWorkingDayMap(AcademicYear $year): array
    {
        $yearId = $year->getId()->toRfc4122();
        if (!isset($this->mapCache[$yearId])) {
            $map = [];
            foreach ($this->nonWorkingDays->findByAcademicYearOrdered($year) as $day) {
                $map[$day->getDate()->format('Y-m-d')] = $day->getDescription();
            }
            $this->mapCache[$yearId] = $map;
        }

        return $this->mapCache[$yearId];
    }
}
