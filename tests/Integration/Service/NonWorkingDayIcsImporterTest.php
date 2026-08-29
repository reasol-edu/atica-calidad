<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Repository\AcademicYearRepository;
use App\Repository\NonWorkingDayRepository;
use App\Service\NonWorkingDayIcsImporter;
use App\Tests\Integration\RepositoryTestCase;

final class NonWorkingDayIcsImporterTest extends RepositoryTestCase
{
    private NonWorkingDayIcsImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var NonWorkingDayIcsImporter $importer */
        $importer       = self::getContainer()->get(NonWorkingDayIcsImporter::class);
        $this->importer = $importer;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function icsFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'holidays_ics_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return $path;
    }

    /** A minimal, valid single-event VCALENDAR body. */
    private function icsWithOneEvent(string $date, string $summary): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Test//Test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1@example.com\r\n"
            . "DTSTAMP:20250101T000000Z\r\n"
            . "DTSTART;VALUE=DATE:{$date}\r\n"
            . "SUMMARY:{$summary}\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    public function testImportsAnEventAsANonWorkingDay(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        $path  = $this->icsFile($this->icsWithOneEvent('20251012', 'Fiesta nacional'));
        $stats = $this->importer->import($path, $year);

        self::assertSame(1, $stats['new']);
        self::assertSame(0, $stats['existing']);

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years        = self::getContainer()->get(AcademicYearRepository::class);
        $reloadedYear = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloadedYear);
        /** @var NonWorkingDayRepository $days */
        $days = self::getContainer()->get(NonWorkingDayRepository::class);
        $all  = $days->findByAcademicYearOrdered($reloadedYear);
        self::assertCount(1, $all);
        self::assertSame('2025-10-12', $all[0]->getDate()->format('Y-m-d'));
        self::assertSame('Fiesta nacional', $all[0]->getDescription());
    }

    public function testDoesNotDuplicateAnAlreadyRegisteredDate(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $existing = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-12'))->setAcademicYear($year);
        $this->persist($centre, $year, $existing);

        $path  = $this->icsFile($this->icsWithOneEvent('20251012', 'Fiesta nacional'));
        $stats = $this->importer->import($path, $year);

        self::assertSame(0, $stats['new']);
        self::assertSame(1, $stats['existing']);
    }
}
