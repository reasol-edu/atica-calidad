<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Entity\PrintableCalendar;
use App\Entity\PrintableCalendarDate;
use App\Entity\PrintableCalendarOrientation;
use App\Entity\PrintableCalendarPeriod;
use App\Entity\PrintableCalendarPeriodMode;
use App\Entity\Teacher;
use App\Repository\AcademicYearRepository;
use App\Repository\PrintableCalendarRepository;
use App\Service\PdfRenderer;
use App\Service\PrintableCalendarPdfBuilder;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Utilidades › Generador de calendarios: PDF calendars a teacher builds for themselves — periods
 * and individual highlighted dates over a two-months-per-row grid (PrintableCalendarPdfBuilder).
 * Entirely personal: PrintableCalendarRepository::findByOwnerAndId only ever resolves the current
 * teacher's own calendars, so there's no separate voter to check.
 */
#[Route('/utilidades/generador-calendarios')]
class CalendarGeneratorController extends AbstractController
{
    use TranslatorTrait;

    private const string COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        private readonly PrintableCalendarRepository $calendars,
        private readonly AcademicYearRepository $academicYears,
        private readonly TenantContext $tenantContext,
        private readonly PrintableCalendarPdfBuilder $pdfBuilder,
        private readonly PdfRenderer $pdf,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_utilities_calendar_generator_index')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $yearId = $request->query->getString('curso');
        $year   = $yearId !== '' ? $this->academicYears->findByCentreAndId($centre, $yearId) : null;
        $year ??= $this->tenantContext->getViewYear($centre) ?? $centre->getActiveAcademicYear();

        $calendars = $year === null ? [] : $this->calendars->findByOwnerAndYear($this->teacher(), $year);

        return $this->render('utilities/calendar_generator/index.html.twig', [
            'centre'        => $centre,
            'year'          => $year,
            'academicYears' => $this->academicYears->findByCentreOrderedByName($centre),
            'calendars'     => $calendars,
        ]);
    }

    #[Route('/nuevo', name: 'app_utilities_calendar_generator_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $values = $this->blankValues();
        $values['academicYear'] = $request->query->getString('curso') ?: ($this->tenantContext->getViewYear($centre) ?? $centre->getActiveAcademicYear())?->getId()->toRfc4122() ?? '';
        [$dateRows, $periodRows] = [[], []];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'utilities_calendar');
            [$values, $dateRows, $periodRows, $errors, $data] = $this->readForm($request, $centre);
            if ($data !== null) {
                $year     = $this->academicYears->findByCentreAndId($centre, $data['academicYear']) ?? throw $this->createNotFoundException();
                $calendar = new PrintableCalendar($centre, $year, $this->teacher(), $data['title']);
                $this->applyCommonFields($calendar, $data);
                $this->applyDates($calendar, $data['dates']);
                $this->applyPeriods($calendar, $data['periods']);
                $this->em->persist($calendar);
                $this->em->flush();
                $this->addFlash('success', $this->t('flash.created'));

                return $this->redirectToRoute('app_utilities_calendar_generator_edit', ['id' => $calendar->getId()->toRfc4122()]);
            }
        }

        return $this->renderForm($centre, null, $values, $dateRows, $periodRows, $errors);
    }

    #[Route('/{id}/editar', name: 'app_utilities_calendar_generator_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $calendar = $this->requireOwn($id, $centre);

        $values     = $this->valuesFor($calendar);
        $dateRows   = $this->dateRowsFor($calendar);
        $periodRows = $this->periodRowsFor($calendar);
        $errors     = [];

        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'utilities_calendar_' . $id);
            [$values, $dateRows, $periodRows, $errors, $data] = $this->readForm($request, $centre);
            if ($data !== null) {
                $year = $this->academicYears->findByCentreAndId($centre, $data['academicYear']) ?? throw $this->createNotFoundException();
                $calendar->setAcademicYear($year);
                $this->applyCommonFields($calendar, $data);
                $this->reconcileDates($calendar, $data['dates']);
                $this->reconcilePeriods($calendar, $data['periods']);
                $this->em->flush();
                $this->addFlash('success', $this->t('flash.saved'));

                if ($request->request->getString('action') === 'save_and_pdf') {
                    return $this->redirectToRoute('app_utilities_calendar_generator_pdf', ['id' => $id]);
                }

                return $this->redirectToRoute('app_utilities_calendar_generator_edit', ['id' => $id]);
            }
        }

        return $this->renderForm($centre, $calendar, $values, $dateRows, $periodRows, $errors);
    }

    #[Route('/{id}/duplicar', name: 'app_utilities_calendar_generator_duplicate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function duplicate(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $calendar = $this->requireOwn($id, $centre);
        $this->checkToken($request, 'utilities_calendar_duplicate_' . $id);

        $data            = $this->dataFromCalendar($calendar);
        $data['title'] .= ' ' . $this->t('duplicate_suffix');

        $copy = new PrintableCalendar($centre, $calendar->getAcademicYear(), $this->teacher(), $data['title']);
        $this->applyCommonFields($copy, $data);
        $this->applyDates($copy, $data['dates']);
        $this->applyPeriods($copy, $data['periods']);
        $this->em->persist($copy);
        $this->em->flush();
        $this->addFlash('success', $this->t('flash.duplicated'));

        return $this->redirectToRoute('app_utilities_calendar_generator_edit', ['id' => $copy->getId()->toRfc4122()]);
    }

    #[Route('/{id}/exportar', name: 'app_utilities_calendar_generator_export', requirements: ['id' => Requirement::UUID])]
    public function export(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $calendar = $this->requireOwn($id, $centre);
        $json     = json_encode(['version' => 1, 'calendar' => $this->exportPayload($this->dataFromCalendar($calendar))],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->slug($calendar->getTitle()) . '.json',
        ));

        return $response;
    }

    #[Route('/importar', name: 'app_utilities_calendar_generator_import', methods: ['GET', 'POST'])]
    public function import(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $academicYear = $request->query->getString('curso') ?: ($this->tenantContext->getViewYear($centre) ?? $centre->getActiveAcademicYear())?->getId()->toRfc4122() ?? '';
        $errors       = [];

        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'utilities_calendar_import');
            $academicYear = $request->request->getString('academicYear');
            $year         = $this->academicYears->findByCentreAndId($centre, $academicYear);
            if ($year === null) {
                $errors['academicYear'] = $this->t('form.error.academic_year');
            }

            $file = $request->files->get('json');
            $data = null;
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $errors['file'] = $this->t('form.error.import_file');
            } else {
                $content = @file_get_contents($file->getPathname());
                $decoded = $content !== false ? json_decode($content, true) : null;
                [$importError, $data] = $this->readImportPayload($decoded);
                if ($importError !== null) {
                    $errors['file'] = $importError;
                }
            }

            if ($errors === [] && $data !== null) {
                $calendar = new PrintableCalendar($centre, $year, $this->teacher(), $data['title']);
                $this->applyCommonFields($calendar, $data);
                $this->applyDates($calendar, $data['dates']);
                $this->applyPeriods($calendar, $data['periods']);
                $this->em->persist($calendar);
                $this->em->flush();
                $this->addFlash('success', $this->t('flash.imported'));

                return $this->redirectToRoute('app_utilities_calendar_generator_edit', ['id' => $calendar->getId()->toRfc4122()]);
            }
        }

        return $this->render('utilities/calendar_generator/import.html.twig', [
            'centre'        => $centre,
            'academicYear'  => $academicYear,
            'academicYears' => $this->academicYears->findByCentreOrderedByName($centre),
            'errors'        => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/{id}/eliminar', name: 'app_utilities_calendar_generator_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $calendar = $this->requireOwn($id, $centre);
        $this->checkToken($request, 'utilities_calendar_' . $id);
        $this->em->remove($calendar);
        $this->em->flush();
        $this->addFlash('success', $this->t('flash.deleted'));

        return $this->redirectToRoute('app_utilities_calendar_generator_index');
    }

    #[Route('/{id}/pdf', name: 'app_utilities_calendar_generator_pdf', requirements: ['id' => Requirement::UUID])]
    public function pdf(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $calendar = $this->requireOwn($id, $centre);
        $data     = $this->pdfBuilder->build($calendar);

        return $this->pdf->render('utilities/pdf/calendar.html.twig', [
            'centre'   => $calendar->getEducationalCentre(),
            'calendar' => $calendar,
            'data'     => $data,
        ], $calendar->getTitle(), $this->slug($calendar->getTitle()) . '.pdf',
            inline: true,
            orientation: $calendar->getOrientation()->pdfCode(),
            centre: $calendar->getEducationalCentre(),
            reportType: 'printable_calendar',
            showHeader: $calendar->isShowHeader(),
            showFooter: $calendar->isShowFooter(),
        );
    }

    private function requireOwn(string $id, EducationalCentre $centre): PrintableCalendar
    {
        return $this->calendars->findByOwnerAndId($this->teacher(), $centre, $id) ?? throw $this->createNotFoundException();
    }

    /**
     * The same shape as readForm()'s parsed $data — applyCommonFields/applyDates/applyPeriods
     * work on it regardless of whether it came from an HTTP form (readForm), an existing entity
     * (here, for duplicate() and export()) or an uploaded file (readImportPayload).
     *
     * @return array{title: string, description: ?string, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, nonWorkingDayColor: ?string, weekendColor: ?string, orientation: PrintableCalendarOrientation, fontSizeScale: int, showHeader: bool, showFooter: bool, showHours: bool, dates: list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}>, periods: list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}>}
     */
    private function dataFromCalendar(PrintableCalendar $calendar): array
    {
        $dates = [];
        foreach ($calendar->getDates() as $date) {
            $dates[] = ['id' => null, 'date' => $date->getDate(), 'color' => $date->getColor(), 'description' => $date->getDescription()];
        }
        $periods = [];
        foreach ($calendar->getPeriods() as $period) {
            $periods[] = [
                'id'                 => null,
                'description'        => $period->getDescription(),
                'color'              => $period->getColor(),
                'showJourneySummary' => $period->isShowJourneySummary(),
                'mode'               => $period->getMode(),
                'startDate'          => $period->getStartDate(),
                'endDate'            => $period->getEndDate(),
                'totalHours'         => $period->getTotalHours(),
                'weekdayHours'       => $period->weekdayHours(),
            ];
        }

        return [
            'title'              => $calendar->getTitle(),
            'description'        => $calendar->getDescription(),
            'startDate'          => $calendar->getStartDate(),
            'endDate'            => $calendar->getEndDate(),
            'nonWorkingDayColor' => $calendar->getNonWorkingDayColor(),
            'weekendColor'       => $calendar->getWeekendColor(),
            'orientation'        => $calendar->getOrientation(),
            'fontSizeScale'      => $calendar->getFontSizeScale(),
            'showHeader'         => $calendar->isShowHeader(),
            'showFooter'         => $calendar->isShowFooter(),
            'showHours'          => $calendar->isShowHours(),
            'dates'              => $dates,
            'periods'            => $periods,
        ];
    }

    /**
     * @param array{title: string, description: ?string, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, nonWorkingDayColor: ?string, weekendColor: ?string, orientation: PrintableCalendarOrientation, fontSizeScale: int, showHeader: bool, showFooter: bool, showHours: bool, dates: list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}>, periods: list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}>} $data
     *
     * @return array<string, mixed>
     */
    private function exportPayload(array $data): array
    {
        return [
            'title'              => $data['title'],
            'description'        => $data['description'],
            'startDate'          => $data['startDate']?->format('Y-m-d'),
            'endDate'            => $data['endDate']?->format('Y-m-d'),
            'nonWorkingDayColor' => $data['nonWorkingDayColor'],
            'weekendColor'       => $data['weekendColor'],
            'orientation'        => $data['orientation']->value,
            'fontSizeScale'      => $data['fontSizeScale'],
            'showHeader'         => $data['showHeader'],
            'showFooter'         => $data['showFooter'],
            'showHours'          => $data['showHours'],
            'dates'              => array_map(static fn (array $d): array => [
                'date'        => $d['date']->format('Y-m-d'),
                'color'       => $d['color'],
                'description' => $d['description'],
            ], $data['dates']),
            'periods' => array_map(static fn (array $p): array => [
                'description'        => $p['description'],
                'color'              => $p['color'],
                'showJourneySummary' => $p['showJourneySummary'],
                'mode'               => $p['mode']->value,
                'startDate'          => $p['startDate']?->format('Y-m-d'),
                'endDate'            => $p['endDate']?->format('Y-m-d'),
                'totalHours'         => $p['totalHours'],
                'mondayHours'        => $p['weekdayHours'][1],
                'tuesdayHours'       => $p['weekdayHours'][2],
                'wednesdayHours'     => $p['weekdayHours'][3],
                'thursdayHours'      => $p['weekdayHours'][4],
                'fridayHours'        => $p['weekdayHours'][5],
            ], $data['periods']),
        ];
    }

    /**
     * The inverse of exportPayload(), from an untrusted decoded JSON value — validates every field
     * from scratch (never trust that an uploaded file matches what export() produces) and returns
     * either a single translated error message, or a $data array in the same shape readForm()
     * produces, ready for applyCommonFields/applyDates/applyPeriods.
     *
     * @return array{0: ?string, 1: ?array{title: string, description: ?string, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, nonWorkingDayColor: ?string, weekendColor: ?string, orientation: PrintableCalendarOrientation, fontSizeScale: int, showHeader: bool, showFooter: bool, showHours: bool, dates: list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}>, periods: list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}>}}
     */
    private function readImportPayload(mixed $decoded): array
    {
        $invalid = [$this->t('form.error.import_invalid'), null];

        if (!\is_array($decoded) || !\is_array($decoded['calendar'] ?? null)) {
            return $invalid;
        }
        $c = $decoded['calendar'];

        $title = \is_string($c['title'] ?? null) ? trim($c['title']) : '';
        if ($title === '' || mb_strlen($title) > 255) {
            return $invalid;
        }
        $description = \is_string($c['description'] ?? null) && trim($c['description']) !== '' ? $c['description'] : null;

        $startDate = self::importDate($c['startDate'] ?? null);
        $endDate   = self::importDate($c['endDate'] ?? null);
        if ($startDate === false || $endDate === false || ($startDate !== null && $endDate !== null && $endDate < $startDate)) {
            return $invalid;
        }

        $nonWorkingDayColor = self::importColor($c['nonWorkingDayColor'] ?? null);
        $weekendColor       = self::importColor($c['weekendColor'] ?? null);
        if ($nonWorkingDayColor === false || $weekendColor === false) {
            return $invalid;
        }

        $orientation = \is_string($c['orientation'] ?? null) ? PrintableCalendarOrientation::tryFrom($c['orientation']) : null;
        if ($orientation === null) {
            return $invalid;
        }

        $fontSizeScale = \is_int($c['fontSizeScale'] ?? null) ? $c['fontSizeScale'] : null;
        if ($fontSizeScale === null || $fontSizeScale < 50 || $fontSizeScale > 200) {
            return $invalid;
        }

        foreach (['showHeader', 'showFooter', 'showHours'] as $flag) {
            if (isset($c[$flag]) && !\is_bool($c[$flag])) {
                return $invalid;
            }
        }

        $dates = [];
        foreach (\is_array($c['dates'] ?? null) ? $c['dates'] : [] as $raw) {
            if (!\is_array($raw)) {
                return $invalid;
            }
            $date = self::importDate($raw['date'] ?? null);
            $color = self::importColor($raw['color'] ?? null);
            $desc  = \is_string($raw['description'] ?? null) ? trim($raw['description']) : '';
            if ($date === null || $date === false || $color === null || $color === false || $desc === '' || mb_strlen($desc) > 255) {
                return $invalid;
            }
            $dates[] = ['id' => null, 'date' => $date, 'color' => $color, 'description' => $desc];
        }

        $periods = [];
        foreach (\is_array($c['periods'] ?? null) ? $c['periods'] : [] as $raw) {
            if (!\is_array($raw)) {
                return $invalid;
            }
            $desc  = \is_string($raw['description'] ?? null) ? trim($raw['description']) : '';
            $color = self::importColor($raw['color'] ?? null);
            $mode  = \is_string($raw['mode'] ?? null) ? PrintableCalendarPeriodMode::tryFrom($raw['mode']) : null;
            if ($desc === '' || mb_strlen($desc) > 255 || $color === null || $color === false || $mode === null) {
                return $invalid;
            }
            if (isset($raw['showJourneySummary']) && !\is_bool($raw['showJourneySummary'])) {
                return $invalid;
            }

            $pStart       = self::importDate($raw['startDate'] ?? null);
            $pEnd         = self::importDate($raw['endDate'] ?? null);
            $totalHours   = self::importNumber($raw['totalHours'] ?? null);
            $weekdayHours = [
                1 => self::importNumber($raw['mondayHours'] ?? null),
                2 => self::importNumber($raw['tuesdayHours'] ?? null),
                3 => self::importNumber($raw['wednesdayHours'] ?? null),
                4 => self::importNumber($raw['thursdayHours'] ?? null),
                5 => self::importNumber($raw['fridayHours'] ?? null),
            ];
            if ($pStart === false || $pEnd === false || $totalHours === false || \in_array(false, $weekdayHours, true)) {
                return $invalid;
            }

            $valid = match ($mode) {
                PrintableCalendarPeriodMode::DateRange => $pStart !== null && $pEnd !== null && $pEnd >= $pStart,
                PrintableCalendarPeriodMode::StartWithHours => $pStart !== null && $totalHours !== null && $totalHours > 0.0 && self::hasPositiveHours($weekdayHours),
                PrintableCalendarPeriodMode::EndWithHours => $pEnd !== null && $totalHours !== null && $totalHours > 0.0 && self::hasPositiveHours($weekdayHours),
            };
            if (!$valid) {
                return $invalid;
            }

            $periods[] = [
                'id'                 => null,
                'description'        => $desc,
                'color'              => $color,
                'showJourneySummary' => ($raw['showJourneySummary'] ?? false) === true,
                'mode'               => $mode,
                'startDate'          => $mode === PrintableCalendarPeriodMode::EndWithHours ? null : $pStart,
                'endDate'            => $mode === PrintableCalendarPeriodMode::StartWithHours ? null : $pEnd,
                'totalHours'         => $mode === PrintableCalendarPeriodMode::DateRange ? null : $totalHours,
                'weekdayHours'       => $mode === PrintableCalendarPeriodMode::DateRange ? [1 => null, 2 => null, 3 => null, 4 => null, 5 => null] : $weekdayHours,
            ];
        }

        return [null, [
            'title'              => $title,
            'description'        => $description,
            'startDate'          => $startDate,
            'endDate'            => $endDate,
            'nonWorkingDayColor' => $nonWorkingDayColor,
            'weekendColor'       => $weekendColor,
            'orientation'        => $orientation,
            'fontSizeScale'      => $fontSizeScale,
            'showHeader'         => (bool) ($c['showHeader'] ?? true),
            'showFooter'         => (bool) ($c['showFooter'] ?? true),
            'showHours'          => (bool) ($c['showHours'] ?? true),
            'dates'              => $dates,
            'periods'            => $periods,
        ]];
    }

    private static function importDate(mixed $value): \DateTimeImmutable|false|null
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            return false;
        }

        return self::parseDate($value) ?? false;
    }

    private static function importColor(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        return \is_string($value) && self::validColor($value) ? $value : false;
    }

    private static function importNumber(mixed $value): float|false|null
    {
        if ($value === null) {
            return null;
        }

        return \is_int($value) || \is_float($value) ? (float) $value : false;
    }

    /** @return array{title: string, description: string, academicYear: string, startDate: string, endDate: string, nonWorkingDayColor: string, weekendColor: string, orientation: string, fontSizeScale: string, showHeader: string, showFooter: string, showHours: string} */
    private function blankValues(): array
    {
        return [
            'title'              => '',
            'description'        => '',
            'academicYear'       => '',
            'startDate'          => '',
            'endDate'            => '',
            'nonWorkingDayColor' => PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR,
            'weekendColor'       => PrintableCalendar::DEFAULT_WEEKEND_COLOR,
            'orientation'        => PrintableCalendarOrientation::Portrait->value,
            'fontSizeScale'      => '100',
            'showHeader'         => '1',
            'showFooter'         => '1',
            'showHours'          => '1',
        ];
    }

    /** @return array{title: string, description: string, academicYear: string, startDate: string, endDate: string, nonWorkingDayColor: string, weekendColor: string, orientation: string, fontSizeScale: string, showHeader: string, showFooter: string, showHours: string} */
    private function valuesFor(PrintableCalendar $calendar): array
    {
        return [
            'title'              => $calendar->getTitle(),
            'description'        => $calendar->getDescription() ?? '',
            'academicYear'       => $calendar->getAcademicYear()->getId()->toRfc4122(),
            'startDate'          => $calendar->getStartDate()?->format('Y-m-d') ?? '',
            'endDate'            => $calendar->getEndDate()?->format('Y-m-d') ?? '',
            'nonWorkingDayColor' => $calendar->getNonWorkingDayColor() ?? PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR,
            'weekendColor'       => $calendar->getWeekendColor() ?? PrintableCalendar::DEFAULT_WEEKEND_COLOR,
            'orientation'        => $calendar->getOrientation()->value,
            'fontSizeScale'      => (string) $calendar->getFontSizeScale(),
            'showHeader'         => $calendar->isShowHeader() ? '1' : '',
            'showFooter'         => $calendar->isShowFooter() ? '1' : '',
            'showHours'          => $calendar->isShowHours() ? '1' : '',
        ];
    }

    /** @return list<array{id: string, date: string, color: string, description: string}> */
    private function dateRowsFor(PrintableCalendar $calendar): array
    {
        $rows = [];
        foreach ($calendar->getDates() as $date) {
            $rows[] = [
                'id'          => $date->getId()->toRfc4122(),
                'date'        => $date->getDate()->format('Y-m-d'),
                'color'       => $date->getColor(),
                'description' => $date->getDescription(),
            ];
        }

        return $rows;
    }

    /** @return list<array{id: string, description: string, color: string, showJourneySummary: string, mode: string, startDate: string, endDate: string, totalHours: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string}> */
    private function periodRowsFor(PrintableCalendar $calendar): array
    {
        $rows = [];
        foreach ($calendar->getPeriods() as $period) {
            $rows[] = [
                'id'                 => $period->getId()->toRfc4122(),
                'description'        => $period->getDescription(),
                'color'              => $period->getColor(),
                'showJourneySummary' => $period->isShowJourneySummary() ? '1' : '',
                'mode'               => $period->getMode()->value,
                'startDate'          => $period->getStartDate()?->format('Y-m-d') ?? '',
                'endDate'            => $period->getEndDate()?->format('Y-m-d') ?? '',
                'totalHours'         => self::number($period->getTotalHours()),
                'mondayHours'        => self::number($period->getMondayHours()),
                'tuesdayHours'       => self::number($period->getTuesdayHours()),
                'wednesdayHours'     => self::number($period->getWednesdayHours()),
                'thursdayHours'      => self::number($period->getThursdayHours()),
                'fridayHours'        => self::number($period->getFridayHours()),
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     0: array{title: string, description: string, academicYear: string, startDate: string, endDate: string, nonWorkingDayColor: string, weekendColor: string, orientation: string, fontSizeScale: string, showHeader: string, showFooter: string, showHours: string},
     *     1: list<array{id: string, date: string, color: string, description: string}>,
     *     2: list<array{id: string, description: string, color: string, showJourneySummary: string, mode: string, startDate: string, endDate: string, totalHours: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string}>,
     *     3: array<string, string>,
     *     4: null|array{
     *         title: string, description: ?string, academicYear: string, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable,
     *         nonWorkingDayColor: ?string, weekendColor: ?string, orientation: PrintableCalendarOrientation, fontSizeScale: int,
     *         showHeader: bool, showFooter: bool, showHours: bool,
     *         dates: list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}>,
     *         periods: list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}>,
     *     }
     * }
     */
    private function readForm(Request $request, EducationalCentre $centre): array
    {
        $values = [
            'title'              => trim($request->request->getString('title')),
            'description'        => trim($request->request->getString('description')),
            'academicYear'       => $request->request->getString('academicYear'),
            'startDate'          => $request->request->getString('startDate'),
            'endDate'            => $request->request->getString('endDate'),
            'nonWorkingDayColor' => $request->request->getString('nonWorkingDayColor'),
            'weekendColor'       => $request->request->getString('weekendColor'),
            'orientation'        => $request->request->getString('orientation'),
            'fontSizeScale'      => $request->request->getString('fontSizeScale'),
            'showHeader'         => $request->request->getString('showHeader') === '1' ? '1' : '',
            'showFooter'         => $request->request->getString('showFooter') === '1' ? '1' : '',
            'showHours'          => $request->request->getString('showHours') === '1' ? '1' : '',
        ];
        $errors = [];

        if ($values['title'] === '') {
            $errors['title'] = $this->t('form.error.title');
        }
        $year = $this->academicYears->findByCentreAndId($centre, $values['academicYear']);
        if ($year === null) {
            $errors['academicYear'] = $this->t('form.error.academic_year');
        }
        $startDate = self::parseDate($values['startDate']);
        $endDate   = self::parseDate($values['endDate']);
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            $errors['endDate'] = $this->t('form.error.end_before_start');
        }
        if (!self::validColor($values['nonWorkingDayColor'])) {
            $errors['nonWorkingDayColor'] = $this->t('form.error.color');
        }
        if (!self::validColor($values['weekendColor'])) {
            $errors['weekendColor'] = $this->t('form.error.color');
        }
        $orientation = PrintableCalendarOrientation::tryFrom($values['orientation']);
        if ($orientation === null) {
            $errors['orientation'] = $this->t('form.error.orientation');
        }
        $fontSizeScale = filter_var($values['fontSizeScale'], \FILTER_VALIDATE_INT);
        if ($fontSizeScale === false || $fontSizeScale < 50 || $fontSizeScale > 200) {
            $errors['fontSizeScale'] = $this->t('form.error.font_size_scale');
        }

        [$dateRows, $dates, $dateErrors] = $this->readDates($request);
        [$periodRows, $periods, $periodErrors] = $this->readPeriods($request);
        $errors += $dateErrors;
        $errors += $periodErrors;

        if ($errors !== [] || $orientation === null || $fontSizeScale === false) {
            return [$values, $dateRows, $periodRows, $errors, null];
        }

        return [$values, $dateRows, $periodRows, $errors, [
            'title'              => $values['title'],
            'description'        => $values['description'] === '' ? null : $values['description'],
            'academicYear'       => $values['academicYear'],
            'startDate'          => $startDate,
            'endDate'            => $endDate,
            'nonWorkingDayColor' => $values['nonWorkingDayColor'] === PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR ? null : $values['nonWorkingDayColor'],
            'weekendColor'       => $values['weekendColor'] === PrintableCalendar::DEFAULT_WEEKEND_COLOR ? null : $values['weekendColor'],
            'orientation'        => $orientation,
            'fontSizeScale'      => $fontSizeScale,
            'showHeader'         => $values['showHeader'] === '1',
            'showFooter'         => $values['showFooter'] === '1',
            'showHours'          => $values['showHours'] === '1',
            'dates'              => $dates,
            'periods'            => $periods,
        ]];
    }

    /**
     * @return array{
     *     0: list<array{id: string, date: string, color: string, description: string}>,
     *     1: list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}>,
     *     2: array<string, string>,
     * }
     */
    private function readDates(Request $request): array
    {
        $rows   = [];
        $parsed = [];
        $errors = [];
        foreach ($request->request->all('dates') as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $row = [
                'id'          => \is_string($raw['id'] ?? null) ? $raw['id'] : '',
                'date'        => \is_string($raw['date'] ?? null) ? $raw['date'] : '',
                'color'       => \is_string($raw['color'] ?? null) ? $raw['color'] : '',
                'description' => \is_string($raw['description'] ?? null) ? trim($raw['description']) : '',
            ];
            if (($raw['remove'] ?? '') === '1' || ($row['date'] === '' && $row['description'] === '')) {
                continue;
            }
            $rows[] = $row;
            $date   = self::parseDate($row['date']);
            if ($date === null || $row['description'] === '' || !self::validColor($row['color'])) {
                $errors['dates'] = $this->t('form.error.dates');

                continue;
            }
            $parsed[] = ['id' => $row['id'] !== '' ? $row['id'] : null, 'date' => $date, 'color' => $row['color'], 'description' => $row['description']];
        }

        // A date repeated in several entries shares one background colour — the first one wins.
        $colorByDate = [];
        foreach ($parsed as $entry) {
            $iso           = $entry['date']->format('Y-m-d');
            $colorByDate[$iso] ??= $entry['color'];
        }
        foreach ($parsed as &$entry) {
            $entry['color'] = $colorByDate[$entry['date']->format('Y-m-d')];
        }
        unset($entry);
        foreach ($rows as &$row) {
            if ($row['date'] !== '' && isset($colorByDate[$row['date']])) {
                $row['color'] = $colorByDate[$row['date']];
            }
        }
        unset($row);

        return [$rows, $parsed, $errors];
    }

    /**
     * @return array{
     *     0: list<array{id: string, description: string, color: string, showJourneySummary: string, mode: string, startDate: string, endDate: string, totalHours: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string}>,
     *     1: list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}>,
     *     2: array<string, string>,
     * }
     */
    private function readPeriods(Request $request): array
    {
        $rows   = [];
        $parsed = [];
        $errors = [];
        foreach ($request->request->all('periods') as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $row = [
                'id'                 => \is_string($raw['id'] ?? null) ? $raw['id'] : '',
                'description'        => \is_string($raw['description'] ?? null) ? trim($raw['description']) : '',
                'color'              => \is_string($raw['color'] ?? null) ? $raw['color'] : '#c7d2fe',
                'showJourneySummary' => ($raw['showJourneySummary'] ?? '') === '1' ? '1' : '',
                'mode'               => \is_string($raw['mode'] ?? null) ? $raw['mode'] : '',
                'startDate'          => \is_string($raw['startDate'] ?? null) ? $raw['startDate'] : '',
                'endDate'            => \is_string($raw['endDate'] ?? null) ? $raw['endDate'] : '',
                'totalHours'         => \is_string($raw['totalHours'] ?? null) ? $raw['totalHours'] : '',
                'mondayHours'        => \is_string($raw['mondayHours'] ?? null) ? $raw['mondayHours'] : '',
                'tuesdayHours'       => \is_string($raw['tuesdayHours'] ?? null) ? $raw['tuesdayHours'] : '',
                'wednesdayHours'     => \is_string($raw['wednesdayHours'] ?? null) ? $raw['wednesdayHours'] : '',
                'thursdayHours'      => \is_string($raw['thursdayHours'] ?? null) ? $raw['thursdayHours'] : '',
                'fridayHours'        => \is_string($raw['fridayHours'] ?? null) ? $raw['fridayHours'] : '',
            ];
            if (($raw['remove'] ?? '') === '1' || ($row['description'] === '' && $row['id'] === '')) {
                continue;
            }
            $rows[] = $row;

            $mode = PrintableCalendarPeriodMode::tryFrom($row['mode']);
            if ($row['description'] === '' || !self::validColor($row['color']) || $mode === null) {
                $errors['periods'] = $this->t('form.error.periods');

                continue;
            }

            $startDate   = self::parseDate($row['startDate']);
            $endDate     = self::parseDate($row['endDate']);
            $totalHours  = self::parseNumber($row['totalHours']);
            $weekdayHours = [
                1 => self::parseNumber($row['mondayHours']),
                2 => self::parseNumber($row['tuesdayHours']),
                3 => self::parseNumber($row['wednesdayHours']),
                4 => self::parseNumber($row['thursdayHours']),
                5 => self::parseNumber($row['fridayHours']),
            ];

            $valid = match ($mode) {
                PrintableCalendarPeriodMode::DateRange => $startDate !== null && $endDate !== null && $endDate >= $startDate,
                PrintableCalendarPeriodMode::StartWithHours => $startDate !== null && $totalHours !== null && $totalHours > 0.0 && self::hasPositiveHours($weekdayHours),
                PrintableCalendarPeriodMode::EndWithHours => $endDate !== null && $totalHours !== null && $totalHours > 0.0 && self::hasPositiveHours($weekdayHours),
            };
            if (!$valid) {
                $errors['periods'] = $this->t('form.error.periods');

                continue;
            }

            $parsed[] = [
                'id'                 => $row['id'] !== '' ? $row['id'] : null,
                'description'        => $row['description'],
                'color'              => $row['color'],
                'showJourneySummary' => $row['showJourneySummary'] === '1',
                'mode'               => $mode,
                'startDate'          => $mode === PrintableCalendarPeriodMode::EndWithHours ? null : $startDate,
                'endDate'            => $mode === PrintableCalendarPeriodMode::StartWithHours ? null : $endDate,
                'totalHours'         => $mode === PrintableCalendarPeriodMode::DateRange ? null : $totalHours,
                'weekdayHours'       => $mode === PrintableCalendarPeriodMode::DateRange ? [1 => null, 2 => null, 3 => null, 4 => null, 5 => null] : $weekdayHours,
            ];
        }

        return [$rows, $parsed, $errors];
    }

    /** @param array{title: string, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, nonWorkingDayColor: ?string, weekendColor: ?string, description: ?string, orientation: PrintableCalendarOrientation, fontSizeScale: int, showHeader: bool, showFooter: bool, showHours: bool} $data */
    private function applyCommonFields(PrintableCalendar $calendar, array $data): void
    {
        $calendar->setTitle($data['title']);
        $calendar->setDescription($data['description']);
        $calendar->setStartDate($data['startDate']);
        $calendar->setEndDate($data['endDate']);
        $calendar->setNonWorkingDayColor($data['nonWorkingDayColor']);
        $calendar->setWeekendColor($data['weekendColor']);
        $calendar->setOrientation($data['orientation']);
        $calendar->setFontSizeScale($data['fontSizeScale']);
        $calendar->setShowHeader($data['showHeader']);
        $calendar->setShowFooter($data['showFooter']);
        $calendar->setShowHours($data['showHours']);
    }

    /** @param list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}> $dates */
    private function applyDates(PrintableCalendar $calendar, array $dates): void
    {
        foreach ($dates as $entry) {
            $calendar->addDate($entry['date'], $entry['color'], $entry['description']);
        }
    }

    /** @param list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}> $periods */
    private function applyPeriods(PrintableCalendar $calendar, array $periods): void
    {
        foreach ($periods as $entry) {
            $calendar->addPeriod(
                $entry['description'],
                $entry['color'],
                $entry['showJourneySummary'],
                $entry['mode'],
                $entry['startDate'],
                $entry['endDate'],
                $entry['totalHours'],
                $entry['weekdayHours'][1],
                $entry['weekdayHours'][2],
                $entry['weekdayHours'][3],
                $entry['weekdayHours'][4],
                $entry['weekdayHours'][5],
            );
        }
    }

    /** @param list<array{id: ?string, date: \DateTimeImmutable, color: string, description: string}> $dates */
    private function reconcileDates(PrintableCalendar $calendar, array $dates): void
    {
        $existing = [];
        foreach ($calendar->getDates() as $date) {
            $existing[$date->getId()->toRfc4122()] = $date;
        }
        $kept = [];
        foreach ($dates as $entry) {
            if ($entry['id'] !== null && isset($existing[$entry['id']])) {
                $row = $existing[$entry['id']];
                $row->setDate($entry['date'])->setColor($entry['color'])->setDescription($entry['description']);
                $kept[] = $entry['id'];
            } else {
                $calendar->addDate($entry['date'], $entry['color'], $entry['description']);
            }
        }
        foreach ($existing as $id => $row) {
            if (!\in_array($id, $kept, true)) {
                $calendar->removeDate($row);
            }
        }
    }

    /** @param list<array{id: ?string, description: string, color: string, showJourneySummary: bool, mode: PrintableCalendarPeriodMode, startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, totalHours: ?float, weekdayHours: array<int, ?float>}> $periods */
    private function reconcilePeriods(PrintableCalendar $calendar, array $periods): void
    {
        $existing = [];
        foreach ($calendar->getPeriods() as $period) {
            $existing[$period->getId()->toRfc4122()] = $period;
        }
        $kept     = [];
        $position = 0;
        foreach ($periods as $entry) {
            if ($entry['id'] !== null && isset($existing[$entry['id']])) {
                $period = $existing[$entry['id']];
                $period->setDescription($entry['description'])
                    ->setColor($entry['color'])
                    ->setShowJourneySummary($entry['showJourneySummary'])
                    ->setMode($entry['mode'])
                    ->setStartDate($entry['startDate'])
                    ->setEndDate($entry['endDate'])
                    ->setTotalHours($entry['totalHours'])
                    ->setMondayHours($entry['weekdayHours'][1])
                    ->setTuesdayHours($entry['weekdayHours'][2])
                    ->setWednesdayHours($entry['weekdayHours'][3])
                    ->setThursdayHours($entry['weekdayHours'][4])
                    ->setFridayHours($entry['weekdayHours'][5])
                    ->setPosition($position);
                $kept[] = $entry['id'];
            } else {
                $period = $calendar->addPeriod(
                    $entry['description'],
                    $entry['color'],
                    $entry['showJourneySummary'],
                    $entry['mode'],
                    $entry['startDate'],
                    $entry['endDate'],
                    $entry['totalHours'],
                    $entry['weekdayHours'][1],
                    $entry['weekdayHours'][2],
                    $entry['weekdayHours'][3],
                    $entry['weekdayHours'][4],
                    $entry['weekdayHours'][5],
                );
                $period->setPosition($position);
            }
            ++$position;
        }
        foreach ($existing as $id => $period) {
            if (!\in_array($id, $kept, true)) {
                $calendar->removePeriod($period);
            }
        }
    }

    /**
     * @param array{title: string, description: string, academicYear: string, startDate: string, endDate: string, nonWorkingDayColor: string, weekendColor: string, orientation: string, fontSizeScale: string, showHeader: string, showFooter: string, showHours: string} $values
     * @param list<array{id: string, date: string, color: string, description: string}>                                                                              $dateRows
     * @param list<array{id: string, description: string, color: string, showJourneySummary: string, mode: string, startDate: string, endDate: string, totalHours: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string}> $periodRows
     * @param array<string, string>                                                                                                                                  $errors
     */
    private function renderForm(EducationalCentre $centre, ?PrintableCalendar $calendar, array $values, array $dateRows, array $periodRows, array $errors): Response
    {
        $blankDate   = ['id' => '', 'date' => '', 'color' => '#a7f3d0', 'description' => ''];
        $blankPeriod = ['id' => '', 'description' => '', 'color' => '#c7d2fe', 'showJourneySummary' => '', 'mode' => PrintableCalendarPeriodMode::DateRange->value, 'startDate' => '', 'endDate' => '', 'totalHours' => '', 'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => ''];

        return $this->render('utilities/calendar_generator/form.html.twig', [
            'centre'       => $centre,
            'calendar'     => $calendar,
            'values'       => $values,
            'dateRows'     => [...$dateRows, $blankDate, $blankDate],
            'periodRows'   => [...$periodRows, $blankPeriod],
            'errors'       => $errors,
            'academicYears' => $this->academicYears->findByCentreOrderedByName($centre),
            'modes'        => PrintableCalendarPeriodMode::cases(),
            'orientations' => PrintableCalendarOrientation::cases(),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    private function translationDomain(): string
    {
        return 'utilities';
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private static function validColor(string $value): bool
    {
        return preg_match(self::COLOR_PATTERN, $value) === 1;
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

    private static function number(?float $value): string
    {
        return $value === null ? '' : Indicator::number($value);
    }

    private function slug(string $text): string
    {
        $slug = strtr(mb_strtolower($text), 'áéíóúñü', 'aeiounu');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'calendario';

        return trim($slug, '-') ?: 'calendario';
    }
}
