<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\NonWorkingDayRepository;
use App\Service\NonWorkingDayCsvImporter;
use App\Tests\Integration\RepositoryTestCase;

final class NonWorkingDayCsvImporterTest extends RepositoryTestCase
{
    private NonWorkingDayCsvImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var NonWorkingDayCsvImporter $importer */
        $importer       = self::getContainer()->get(NonWorkingDayCsvImporter::class);
        $this->importer = $importer;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function csvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'seneca_csv_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return $path;
    }

    public function testImportsOnlyRowsAffectingTeachingStaff(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        $csv = "Fecha,Descripción de la festividad,Afecta al personal docente\n"
            . "12/10/2025,Fiesta nacional,Si\n"
            . "24/12/2025,Puente no docente,No\n";
        $path = $this->csvFile($csv);

        $stats = $this->importer->import($path, $year);

        self::assertSame(1, $stats['new']);
        self::assertSame(0, $stats['existing']);
        self::assertSame(1, $stats['skipped']);

        $this->em->clear();
        /** @var NonWorkingDayRepository $days */
        $days = self::getContainer()->get(NonWorkingDayRepository::class);
        /** @var AcademicYear $reloadedYear */
        $reloadedYear = self::getContainer()->get(\App\Repository\AcademicYearRepository::class)->findById($year->getId()->toRfc4122());
        $all = $days->findByAcademicYearOrdered($reloadedYear);
        self::assertCount(1, $all);
        self::assertSame('2025-10-12', $all[0]->getDate()->format('Y-m-d'));
        self::assertSame('Fiesta nacional', $all[0]->getDescription());
    }

    public function testSkipsRowsWithAnUnparsableDate(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        $csv = "Fecha,Descripción de la festividad,Afecta al personal docente\n"
            . "no-es-una-fecha,Algo,Si\n";
        $path = $this->csvFile($csv);

        $stats = $this->importer->import($path, $year);

        self::assertSame(0, $stats['new']);
        self::assertSame(1, $stats['skipped']);
    }

    public function testDoesNotDuplicateAnAlreadyRegisteredDate(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $existing = (new \App\Entity\NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-12'))->setAcademicYear($year);
        $this->persist($centre, $year, $existing);

        $csv = "Fecha,Descripción de la festividad,Afecta al personal docente\n"
            . "12/10/2025,Fiesta nacional,Si\n";
        $path = $this->csvFile($csv);

        $stats = $this->importer->import($path, $year);

        self::assertSame(0, $stats['new']);
        self::assertSame(1, $stats['existing']);
    }

    public function testDeduplicatesRepeatedDatesWithinTheSameFile(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        $csv = "Fecha,Descripción de la festividad,Afecta al personal docente\n"
            . "12/10/2025,Primera vez,Si\n"
            . "12/10/2025,Repetido,Si\n";
        $path = $this->csvFile($csv);

        $stats = $this->importer->import($path, $year);

        self::assertSame(1, $stats['new']);
        self::assertSame(1, $stats['existing']);
    }

    public function testThrowsWhenARequiredColumnIsMissing(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        $csv  = "Fecha,Afecta al personal docente\n12/10/2025,Si\n";
        $path = $this->csvFile($csv);

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import($path, $year);
    }
}
