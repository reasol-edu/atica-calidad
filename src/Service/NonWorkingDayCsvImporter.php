<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\NonWorkingDay;
use App\Repository\NonWorkingDayRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports non-working days from a Séneca CSV export
 * ("Centro > Organización del centro > Calendario y Jornada > Calendario escolar > Días festivos").
 * Only rows where "Afecta al personal docente" is "Si" are imported.
 */
class NonWorkingDayCsvImporter
{
    private const COL_DATE        = 'Fecha';
    private const COL_DESCRIPTION = 'Descripción de la festividad';
    private const COL_TEACHING    = 'Afecta al personal docente';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NonWorkingDayRepository $nonWorkingDays,
        private readonly CsvReader $csvReader,
    ) {}

    /** @return array{new: int, existing: int, skipped: int} */
    public function import(string $filePath, AcademicYear $year): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read file.');
        }

        $parsed  = $this->csvReader->parse($content);
        $missing = $this->csvReader->findMissingColumn($parsed['headers'], [self::COL_DATE, self::COL_DESCRIPTION, self::COL_TEACHING]);
        if ($missing !== null) {
            throw new \InvalidArgumentException("Missing column: {$missing}");
        }

        $new      = 0;
        $existing = 0;
        $skipped  = 0;
        $seen     = [];

        foreach ($parsed['rows'] as $row) {
            if (mb_strtolower($row[self::COL_TEACHING] ?? '', 'UTF-8') !== 'si') {
                $skipped++;
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $row[self::COL_DATE] ?? '');
            if ($date === false) {
                $skipped++;
                continue;
            }

            $dateKey = $date->format('Y-m-d');
            if (isset($seen[$dateKey]) || $this->nonWorkingDays->findByAcademicYearAndDate($year, $date) !== null) {
                $existing++;
                continue;
            }

            $seen[$dateKey] = true;
            $description    = trim($row[self::COL_DESCRIPTION] ?? '');

            $nonWorkingDay = (new NonWorkingDay())
                ->setDate($date)
                ->setDescription($description !== '' ? $description : null)
                ->setAcademicYear($year);

            $this->em->persist($nonWorkingDay);
            $new++;
        }

        $this->em->flush();

        return ['new' => $new, 'existing' => $existing, 'skipped' => $skipped];
    }
}
