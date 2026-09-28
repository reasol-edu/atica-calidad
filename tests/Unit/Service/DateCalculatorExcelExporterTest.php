<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Model\DateCalculatorDay;
use App\Model\DateCalculatorMonth;
use App\Model\DateCalculatorWeek;
use App\Service\DateCalculatorExcelExporter;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * DateCalculatorExcelExporter ports the local orecalc repository's date-list export
 * (CalendarExportService::export()): extra blank columns, showing every date, one block per month
 * and shading alternating weeks, with the same SUM()/COUNT() formulas — these tests build a small
 * two-week DateCalculatorMonth by hand (rather than going through DateCalculatorService) so each
 * option can be checked in isolation.
 */
final class DateCalculatorExcelExporterTest extends TestCase
{
    private DateCalculatorExcelExporter $exporter;

    protected function setUp(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 3) . '/translations/utilities.es.yaml', 'es', 'utilities');
        $translator->addResource('yaml', \dirname(__DIR__, 3) . '/translations/calendar.es.yaml', 'es', 'calendar');

        $this->exporter = new DateCalculatorExcelExporter($translator);
    }

    /** @return list<array<int|string, mixed>> */
    private function readRows(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    /** One week (Mon 2027-03-01 .. Sun 2027-03-07), 8h Monday-Friday, weekend at 0h. */
    private function week(): DateCalculatorWeek
    {
        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $date    = new \DateTimeImmutable('2027-03-0' . ($i + 1));
            $hours   = $i < 5 ? 8.0 : 0.0;
            $days[]  = new DateCalculatorDay($date, $hours, $hours > 0.0 ? (string) $hours : null);
        }

        return new DateCalculatorWeek($days, 40.0, '40');
    }

    /** @return list<DateCalculatorMonth> one month (March 2027), a single working week. */
    private function months(): array
    {
        return [new DateCalculatorMonth(2027, 3, [$this->week()], 40.0, '40', 5)];
    }

    public function testResponseHasTheExpectedContentTypeAndFilename(): void
    {
        $response = $this->exporter->export($this->months(), 'jornadas.xlsx', 0, false, true, false);

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertStringContainsString('jornadas.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    public function testWithoutShowAllDatesOnlyTheWorkingDaysAreListed(): void
    {
        $response = $this->exporter->export($this->months(), 'jornadas.xlsx', 0, false, true, false);
        $rows     = $this->readRows($response->getFile()->getPathname());

        // Header + month title + 5 working days (Mon-Fri) + subtotal = 8 rows (weekend skipped).
        self::assertCount(8, $rows);
        self::assertSame(['Fecha', 'Día', 'Horas', 'Previsto / realizado'], $rows[0]);
        self::assertSame('Marzo 2027', $rows[1][0]);
        self::assertSame('L', $rows[2][1]);
        self::assertSame(8, $rows[2][2]);
    }

    public function testShowAllDatesIncludesTheWeekendAsZeroHourRows(): void
    {
        $response = $this->exporter->export($this->months(), 'jornadas.xlsx', 0, true, true, false);
        $rows     = $this->readRows($response->getFile()->getPathname());

        // Header + month title + 7 days (the whole week) + subtotal = 10 rows.
        self::assertCount(10, $rows);
        self::assertSame('S', $rows[7][1]);
        self::assertSame('', $rows[7][2]);
    }

    public function testTheSubtotalRowUsesSumAndCountFormulasOverTheDataRows(): void
    {
        $response = $this->exporter->export($this->months(), 'jornadas.xlsx', 0, false, true, false);
        $rows     = $this->readRows($response->getFile()->getPathname());

        // Row 2 is the month title, rows 3-7 are Monday-Friday, row 8 is the subtotal.
        self::assertSame('Total', $rows[7][0]);
        self::assertSame('=SUM(C3:C7)', $rows[7][2]);
        self::assertSame('=COUNT(C3:C7)&" jornadas con horas"', $rows[7][3]);
    }

    public function testWithoutSeparateByMonthsThereIsNoMonthTitleOrPerMonthSubtotal(): void
    {
        $response = $this->exporter->export($this->months(), 'jornadas.xlsx', 0, false, false, false);
        $rows     = $this->readRows($response->getFile()->getPathname());

        // Header + 5 working days + one overall subtotal = 7 rows, no "Marzo 2027" title row.
        self::assertCount(7, $rows);
        self::assertSame('L', $rows[1][1]);
        self::assertSame('=SUM(C2:C6)', $rows[6][2]);
    }

    public function testExtraColumnsAddHeadersAndAGrandTotalFormulaRow(): void
    {
        $response = $this->exporter->export($this->months(), 'jornadas.xlsx', 2, false, false, false);
        $rows     = $this->readRows($response->getFile()->getPathname());

        self::assertSame(['Fecha', 'Día', 'Horas', 'Previsto / realizado', 'Extra 1', 'Extra 2'], $rows[0]);
        $lastRow = $rows[\count($rows) - 1];
        self::assertSame('Total columnas extra', $lastRow[0]);
        self::assertSame('=SUM(E2:E6)', $lastRow[4]);
        self::assertSame('=SUM(F2:F6)', $lastRow[5]);
    }
}
