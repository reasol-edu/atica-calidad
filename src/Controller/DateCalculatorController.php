<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Entity\PrintableCalendarPeriodMode;
use App\Repository\AcademicYearRepository;
use App\Service\DateCalculatorExcelExporter;
use App\Service\DateCalculatorService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Utilidades › Calculadora de fechas: a small, stateless tool — nothing persisted, no owner, one
 * request in, one result out — for the same three calculations a PrintableCalendarPeriod already
 * makes for the calendar generator (PrintableCalendarPeriodMode, reused here as the mode selector):
 * working days/hours between two dates, or the date that closes a target number of hours starting
 * from (or ending at) a known one. DateCalculatorService does the actual work, on top of the exact
 * day-walking algorithm the calendar generator uses (WeekdayHoursWalker).
 */
#[Route('/utilidades/calculadora-fechas')]
class DateCalculatorController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly AcademicYearRepository $academicYears,
        private readonly TenantContext $tenantContext,
        private readonly DateCalculatorService $calculator,
        private readonly DateCalculatorExcelExporter $excelExporter,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('', name: 'app_utilities_date_calculator', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $values = $this->blankValues($centre);
        $errors = [];
        $result = null;

        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            [$values, $errors, $result] = $this->readAndCalculate($request, $centre);

            if ($errors === [] && $result !== null && $request->request->getString('action') === 'export_excel') {
                return $this->exportExcel($values, $result);
            }
        }

        return $this->render('utilities/date_calculator/index.html.twig', [
            'centre'          => $centre,
            'academicYears'   => $this->academicYears->findByCentreOrderedByName($centre),
            'modes'           => PrintableCalendarPeriodMode::cases(),
            'values'          => $values,
            'errors'          => $errors,
            'result'          => $result,
            'totalHoursLabel' => $result !== null ? Indicator::number($result['totalHours']) : null,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** @return array{academicYear: string, mode: string, startDate: string, endDate: string, totalHours: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string, extraColumns: string, showAllDates: string, separateByMonths: string, separateWeeksVisually: string} */
    private function blankValues(EducationalCentre $centre): array
    {
        return [
            'academicYear'          => ($this->tenantContext->getViewYear($centre) ?? $centre->getActiveAcademicYear())?->getId()->toRfc4122() ?? '',
            'mode'                  => PrintableCalendarPeriodMode::DateRange->value,
            'startDate'             => '',
            'endDate'               => '',
            'totalHours'            => '',
            'mondayHours'           => '',
            'tuesdayHours'          => '',
            'wednesdayHours'        => '',
            'thursdayHours'         => '',
            'fridayHours'           => '',
            'extraColumns'          => '0',
            'showAllDates'          => '',
            'separateByMonths'      => '1',
            'separateWeeksVisually' => '',
        ];
    }

    /**
     * @return array{
     *     0: array{academicYear: string, mode: string, startDate: string, endDate: string, totalHours: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string, extraColumns: string, showAllDates: string, separateByMonths: string, separateWeeksVisually: string},
     *     1: array<string, string>,
     *     2: null|array{workingDays: int, totalHours: float, totalDays: int, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>, months: list<\App\Model\DateCalculatorMonth>, end?: \DateTimeImmutable, start?: \DateTimeImmutable, completed?: bool},
     * }
     */
    private function readAndCalculate(Request $request, EducationalCentre $centre): array
    {
        $values = [
            'academicYear'          => $request->request->getString('academicYear'),
            'mode'                  => $request->request->getString('mode'),
            'startDate'             => $request->request->getString('startDate'),
            'endDate'               => $request->request->getString('endDate'),
            'totalHours'            => $request->request->getString('totalHours'),
            'mondayHours'           => $request->request->getString('mondayHours'),
            'tuesdayHours'          => $request->request->getString('tuesdayHours'),
            'wednesdayHours'        => $request->request->getString('wednesdayHours'),
            'thursdayHours'         => $request->request->getString('thursdayHours'),
            'fridayHours'           => $request->request->getString('fridayHours'),
            'extraColumns'          => $request->request->getString('extraColumns', '0'),
            'showAllDates'          => $request->request->getString('showAllDates') === '1' ? '1' : '',
            'separateByMonths'      => $request->request->getString('separateByMonths') === '1' ? '1' : '',
            'separateWeeksVisually' => $request->request->getString('separateWeeksVisually') === '1' ? '1' : '',
        ];
        $errors = [];

        $year = $this->academicYears->findByCentreAndId($centre, $values['academicYear']);
        if ($year === null) {
            $errors['academicYear'] = $this->t('form.error.academic_year');
        }

        $mode = PrintableCalendarPeriodMode::tryFrom($values['mode']);
        if ($mode === null) {
            $errors['mode'] = $this->t('form.error.mode');
        }

        if ($errors !== [] || $mode === null) {
            return [$values, $errors, null];
        }

        $startDate  = self::parseDate($values['startDate']);
        $endDate    = self::parseDate($values['endDate']);
        $totalHours = self::parseNumber($values['totalHours']);
        $weekdayHours = [
            1 => self::parseNumber($values['mondayHours']),
            2 => self::parseNumber($values['tuesdayHours']),
            3 => self::parseNumber($values['wednesdayHours']),
            4 => self::parseNumber($values['thursdayHours']),
            5 => self::parseNumber($values['fridayHours']),
        ];
        $hasHours = self::hasPositiveHours($weekdayHours);

        // match(true), not match($mode): each arm's own condition (not a boolean stored earlier)
        // is what lets the date/hours values it uses be checked for null right there.
        $result = match (true) {
            $mode === PrintableCalendarPeriodMode::DateRange && $startDate !== null && $endDate !== null && $endDate >= $startDate && $hasHours
                => $this->calculator->workingDaysBetween($year, $startDate, $endDate, $weekdayHours),
            $mode === PrintableCalendarPeriodMode::StartWithHours && $startDate !== null && $totalHours !== null && $totalHours > 0.0 && $hasHours
                => $this->calculator->endDateForHours($year, $startDate, $totalHours, $weekdayHours),
            $mode === PrintableCalendarPeriodMode::EndWithHours && $endDate !== null && $totalHours !== null && $totalHours > 0.0 && $hasHours
                => $this->calculator->startDateForHours($year, $endDate, $totalHours, $weekdayHours),
            default => null,
        };

        if ($result === null) {
            $errors['form'] = $this->t('date_calculator.error.form');

            return [$values, $errors, null];
        }

        return [$values, $errors, $result];
    }

    /**
     * @param array{extraColumns: string, showAllDates: string, separateByMonths: string, separateWeeksVisually: string} $values
     * @param array{months: list<\App\Model\DateCalculatorMonth>}                                                       $result
     */
    private function exportExcel(array $values, array $result): Response
    {
        $extraColumns = filter_var($values['extraColumns'], \FILTER_VALIDATE_INT) ?: 0;
        $filename     = $this->t('date_calculator.export.filename') . '-' . $this->clock->now()->format('Y-m-d') . '.xlsx';

        return $this->excelExporter->export(
            $result['months'],
            $filename,
            $extraColumns,
            $values['showAllDates'] === '1',
            $values['separateByMonths'] === '1',
            $values['separateWeeksVisually'] === '1',
        );
    }

    private function translationDomain(): string
    {
        return 'utilities';
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('utilities_date_calculator', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /** "4,5" or "4.5" → 4.5; anything else → null. */
    private static function parseNumber(string $text): ?float
    {
        $text = str_replace([' ', ','], ['', '.'], trim($text));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** @param array<int, ?float> $weekdayHours */
    private static function hasPositiveHours(array $weekdayHours): bool
    {
        foreach ($weekdayHours as $hours) {
            if ($hours !== null && $hours > 0.0) {
                return true;
            }
        }

        return false;
    }
}
