<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\NonWorkingDayRepository;

final class NonWorkingDayChecker
{
    /** @var array<string, array<string, ?string>> curso académico (uuid) => [fecha ISO => descripción] */
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
     * Fechas ISO (Y-m-d) de los festivos declarados del curso, para inyectar en
     * los controladores Stimulus que bloquean la selección de fechas no lectivas.
     *
     * @return list<string>
     */
    public function datesFor(AcademicYear $year): array
    {
        return array_keys($this->nonWorkingDayMap($year));
    }

    /**
     * Fechas no lectivas registradas del curso, cargadas en una sola consulta y
     * memoizadas por curso académico para el resto de la petición: evita el N+1
     * que suponía consultar la base de datos por cada día visible del calendario.
     *
     * @return array<string, ?string> fecha ISO (Y-m-d) => descripción (o null)
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
