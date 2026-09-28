<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\DateCalculatorDay;
use App\Model\DateCalculatorMonth;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The date calculator's "export the list of working days" (Utilidades › Calculadora de fechas),
 * porting the options and formulas of the local orecalc repository's equivalent
 * (CalendarExportService::export()) that this tool is otherwise a from-scratch reimplementation
 * of: extra blank columns, showing every date (not just the ones with hours), splitting the sheet
 * into one block per month, and shading alternating weeks. Kept separate from the app's shared
 * XlsxExporter (a plain headers+rows table) since none of the above — formulas, merged month
 * titles, per-row background colours — fits that simpler contract.
 */
final class DateCalculatorExcelExporter
{
    private const string CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const int DATE_COLUMN = 0;
    private const int WEEKDAY_COLUMN = 1;
    private const int HOURS_COLUMN = 2;
    private const int NOTES_COLUMN = 3;
    private const int FIRST_EXTRA_COLUMN = 4;

    /** Single-letter weekday abbreviations, indexed like DateTimeImmutable::format('N') - 1 (0 = Monday). */
    private const array WEEKDAY_LETTERS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    private const string WEEK_BAND_COLOR = 'E8EDF7';
    private const string GRAY_TEXT = '999999';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @param list<DateCalculatorMonth> $months
     */
    public function export(
        array $months,
        string $filename,
        int $extraColumns,
        bool $showAllDates,
        bool $separateByMonths,
        bool $separateWeeksVisually,
    ): BinaryFileResponse {
        $extraColumns = max(0, min(20, $extraColumns));
        $lastColumn   = self::FIRST_EXTRA_COLUMN + $extraColumns - 1;

        $options = new Options();
        $writer  = new Writer($options);

        $tempPath = sys_get_temp_dir() . '/' . uniqid('atica_export_', true) . '.xlsx';
        $writer->openToFile($tempPath);
        $sheet = $writer->getCurrentSheet();

        $this->configureColumns($sheet, $lastColumn);
        $writer->addRow($this->headerRow($extraColumns));

        $row                      = 2;
        $hasAnyRows               = false;
        $extraColumnsFirstDataRow = 0;
        $extraColumnsLastDataRow  = 0;

        foreach ($this->blocks($months, $showAllDates, $separateByMonths) as $block) {
            if ($block['title'] !== null) {
                $writer->addRow($this->titleRow($block['title'], $lastColumn));
                $options->mergeCells(self::DATE_COLUMN, $row, $lastColumn, $row);
                ++$row;
            }

            $firstDataRow = $row;
            if (!$hasAnyRows) {
                $extraColumnsFirstDataRow = $firstDataRow;
                $hasAnyRows               = true;
            }

            $previousWeekKey = null;
            $weekBand        = false;
            foreach ($block['days'] as $day) {
                if ($separateWeeksVisually) {
                    $weekKey = $day->date->format('o-W');
                    if ($weekKey !== $previousWeekKey) {
                        $weekBand        = !$weekBand;
                        $previousWeekKey = $weekKey;
                    }
                } else {
                    $weekBand = false;
                }

                $writer->addRow($this->dayRow($day, $weekBand));
                ++$row;
            }
            $lastDataRow             = $row - 1;
            $extraColumnsLastDataRow = $lastDataRow;

            $writer->addRow($this->subtotalRow($firstDataRow, $lastDataRow));
            ++$row;
            ++$row; // Blank spacer row before the next block.
        }

        if ($extraColumns > 0 && $hasAnyRows) {
            $writer->addRow($this->extraColumnsGrandTotalRow($extraColumns, $extraColumnsFirstDataRow, $extraColumnsLastDataRow));
        }

        $writer->close();

        $response = new BinaryFileResponse($tempPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Splits the months into the blocks the sheet is built from — one block per month
     * ($separateByMonths), or the whole thing as a single, title-less block otherwise.
     *
     * @param list<DateCalculatorMonth> $months
     *
     * @return list<array{title: ?string, days: list<DateCalculatorDay>}>
     */
    private function blocks(array $months, bool $showAllDates, bool $separateByMonths): array
    {
        if ($separateByMonths) {
            $blocks = [];
            foreach ($months as $month) {
                $days = $this->monthDays($month, $showAllDates);
                if ($days !== []) {
                    $blocks[] = ['title' => $this->monthLabel($month), 'days' => $days];
                }
            }

            return $blocks;
        }

        $days = [];
        foreach ($months as $month) {
            $days = array_merge($days, $this->monthDays($month, $showAllDates));
        }

        return $days !== [] ? [['title' => null, 'days' => $days]] : [];
    }

    /** @return list<DateCalculatorDay> */
    private function monthDays(DateCalculatorMonth $month, bool $showAllDates): array
    {
        $days = [];
        foreach ($month->weeks as $week) {
            foreach ($week->days as $day) {
                if ($day === null) {
                    continue;
                }
                if ($day->hours <= 0.0 && !$showAllDates) {
                    continue;
                }
                $days[] = $day;
            }
        }

        return $days;
    }

    private function monthLabel(DateCalculatorMonth $month): string
    {
        $label = $this->translator->trans('month.' . $month->month, [], 'calendar');

        return ucfirst($label) . ' ' . $month->year;
    }

    private function configureColumns(Sheet $sheet, int $lastColumn): void
    {
        // setColumnWidth() columns are 1-indexed, unlike the 0-indexed Row cells above.
        $sheet->setColumnWidth(11, self::DATE_COLUMN + 1);
        $sheet->setColumnWidth(5, self::WEEKDAY_COLUMN + 1);
        $sheet->setColumnWidth(6, self::HOURS_COLUMN + 1);
        $sheet->setColumnWidth(70, self::NOTES_COLUMN + 1);
        for ($column = self::FIRST_EXTRA_COLUMN; $column <= $lastColumn; ++$column) {
            $sheet->setColumnWidth(6, $column + 1);
        }
    }

    private function headerRow(int $extraColumns): Row
    {
        $bold = (new Style())->withFontBold(true);

        $cells = [
            self::DATE_COLUMN    => Cell::fromValue($this->translator->trans('date_calculator.export.date', [], 'utilities'), $bold),
            self::WEEKDAY_COLUMN => Cell::fromValue($this->translator->trans('date_calculator.export.weekday', [], 'utilities'), $bold),
            self::HOURS_COLUMN   => Cell::fromValue($this->translator->trans('date_calculator.export.hours', [], 'utilities'), $bold),
            self::NOTES_COLUMN   => Cell::fromValue($this->translator->trans('date_calculator.export.notes', [], 'utilities'), $bold),
        ];
        for ($i = 1; $i <= $extraColumns; ++$i) {
            $cells[self::FIRST_EXTRA_COLUMN + $i - 1] = Cell::fromValue($this->translator->trans('date_calculator.export.extra_column', ['%number%' => $i], 'utilities'), $bold);
        }
        ksort($cells);

        return new Row($cells);
    }

    private function titleRow(string $title, int $lastColumn): Row
    {
        $style = (new Style())->withFontBold(true);
        $cells = [self::DATE_COLUMN => Cell::fromValue($title, $style)];
        for ($column = self::DATE_COLUMN + 1; $column <= $lastColumn; ++$column) {
            $cells[$column] = Cell::fromValue('', $style);
        }

        return new Row($cells);
    }

    private function dayRow(DateCalculatorDay $day, bool $weekBand): Row
    {
        $style = new Style();
        if ($weekBand) {
            $style = $style->withBackgroundColor(self::WEEK_BAND_COLOR);
        }
        if ($day->hours <= 0.0) {
            $style = $style->withFontColor(self::GRAY_TEXT);
        }
        $dateStyle = $style->withFormat('dd/mm/yyyy');

        return new Row([
            self::DATE_COLUMN    => Cell::fromValue($day->date, $dateStyle),
            self::WEEKDAY_COLUMN => Cell::fromValue(self::WEEKDAY_LETTERS[((int) $day->date->format('N')) - 1], $style->withCellAlignment(CellAlignment::CENTER)),
            self::HOURS_COLUMN   => Cell::fromValue($day->hours > 0.0 ? $day->hours : '', $style),
            self::NOTES_COLUMN   => Cell::fromValue('', $style),
        ]);
    }

    private function subtotalRow(int $firstDataRow, int $lastDataRow): Row
    {
        $bold        = (new Style())->withFontBold(true);
        $hoursLetter = $this->columnLetter(self::HOURS_COLUMN);

        return new Row([
            self::DATE_COLUMN  => Cell::fromValue($this->translator->trans('date_calculator.export.total', [], 'utilities'), $bold),
            self::HOURS_COLUMN => Cell::fromValue("=SUM({$hoursLetter}{$firstDataRow}:{$hoursLetter}{$lastDataRow})", $bold),
            self::NOTES_COLUMN => Cell::fromValue("=COUNT({$hoursLetter}{$firstDataRow}:{$hoursLetter}{$lastDataRow})&\" " . $this->translator->trans('date_calculator.export.working_days_suffix', [], 'utilities') . '"', $bold),
        ]);
    }

    private function extraColumnsGrandTotalRow(int $extraColumns, int $firstDataRow, int $lastDataRow): Row
    {
        $bold  = (new Style())->withFontBold(true);
        $cells = [self::DATE_COLUMN => Cell::fromValue($this->translator->trans('date_calculator.export.extra_columns_total', [], 'utilities'), $bold)];
        for ($i = 0; $i < $extraColumns; ++$i) {
            $column         = self::FIRST_EXTRA_COLUMN + $i;
            $letter         = $this->columnLetter($column);
            $cells[$column] = Cell::fromValue("=SUM({$letter}{$firstDataRow}:{$letter}{$lastDataRow})", $bold);
        }
        ksort($cells);

        return new Row($cells);
    }

    /** 0-indexed column number to an Excel column letter (0 => A, 25 => Z, 26 => AA...). */
    private function columnLetter(int $column): string
    {
        $letter = '';
        ++$column;
        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letter    = \chr(65 + $remainder) . $letter;
            $column    = intdiv($column - 1, 26);
        }

        return $letter;
    }
}
