<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Entity\IndicatorStatus;
use App\Entity\Measurement;
use App\Entity\MeasurementCalendar;
use App\Entity\Teacher;
use App\Model\IndicatorRow;
use App\Repository\DocumentSectionRepository;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Repository\IndicatorRepository;
use App\Repository\MeasurementCalendarRepository;
use App\Repository\MeasurementRepository;
use App\Security\Voter\QualityVoter;
use App\Service\IndicatorBoardBuilder;
use App\Service\IndicatorService;
use App\Service\MeasurementCalendarTemplates;
use App\Service\ResponsibleChoices;
use App\Service\SectionChoiceBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Indicators: the board of the year being viewed, an indicator's page (its periods, values and
 * chart, and recording a value), defining indicators, deciding on a value off target, and the
 * measurement calendars. The board is for whoever sees everything in "Mejora continua"; an
 * indicator's page, also for its responsible, who records its values; defining indicators and
 * calendars, for whoever manages it (QualityVoter). Nothing changes while viewing a past year.
 */
#[Route('/mejora/indicadores')]
class IndicatorController extends AbstractController
{
    public function __construct(
        private readonly IndicatorRepository $indicators,
        private readonly MeasurementCalendarRepository $calendars,
        private readonly MeasurementRepository $measurements,
        private readonly DocumentSectionRepository $sections,
        private readonly FindingRepository $findings,
        private readonly ImprovementActionRepository $actions,
        private readonly IndicatorService $service,
        private readonly IndicatorBoardBuilder $board,
        private readonly ResponsibleChoices $responsibles,
        private readonly SectionChoiceBuilder $sectionChoices,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_quality_indicators')]
    public function board(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);
        $year   = $this->tenantContext->getViewYear($centre);
        $groups = $year === null ? [] : $this->board->board($centre, $year);

        $counts = array_fill_keys(array_map(static fn (IndicatorStatus $s): string => $s->value, IndicatorStatus::cases()), 0);
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if ($row->status !== null) {
                    ++$counts[$row->status->value];
                }
            }
        }
        $status = IndicatorStatus::tryFrom($request->query->getString('estado'));
        if ($status !== null) {
            $groups = array_filter(array_map(
                static fn (array $rows): array => array_values(array_filter($rows, static fn (IndicatorRow $r): bool => $r->status === $status)),
                $groups,
            ));
        }

        return $this->render('quality/indicators.html.twig', [
            'centre'    => $centre,
            'year'      => $year,
            'groups'    => $groups,
            'counts'    => $counts,
            'status'    => $status,
            'canManage' => $this->isGranted(QualityVoter::MANAGE, $centre),
            'writable'  => !$this->tenantContext->isViewingNonActiveYear($centre),
            'hasCalendars' => $year !== null && $this->calendars->findByYear($year) !== [],
        ]);
    }

    #[Route('/nuevo', name: 'app_quality_indicator_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $year = $this->writableYear($centre);

        $values = ['name' => '', 'description' => '', 'section' => '', 'unit' => '%', 'direction' => 'higher', 'responsible' => '', 'active' => '1', 'calendar' => '', 'target' => '', 'alertThreshold' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_indicator');
            [$values, $errors, $data] = $this->readIndicatorForm($request, $centre, $year, null);
            if ($data !== null) {
                $indicator = $this->service->saveIndicator($centre, null, $year, $data);
                $this->addFlash('success', $this->t('indicator.flash.added'));

                return $this->redirectToRoute('app_quality_indicator', ['id' => $indicator->getId()->toRfc4122()]);
            }
        }

        return $this->renderIndicatorForm($centre, $year, null, $values, $errors);
    }

    #[Route('/{id}', name: 'app_quality_indicator', requirements: ['id' => Requirement::UUID])]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $indicator = $this->requireIndicator($id, $centre, QualityVoter::INDICATOR_VIEW);
        $year      = $this->tenantContext->getViewYear($centre);
        $writable  = !$this->tenantContext->isViewingNonActiveYear($centre);
        $row       = $year === null ? null : $this->board->row($indicator, $year);

        // What was opened from each value — findings and plan actions — by measurement id, as links.
        $measured  = array_values(array_filter(array_map(static fn ($c): ?Measurement => $c->measurement, $row->cells ?? [])));
        $followUps = [];
        foreach ($this->findings->findByMeasurements($measured) as $finding) {
            $followUps[$finding->getMeasurement()?->getId()->toRfc4122() ?? ''][] = [
                'label' => $finding->getCode() ?? $finding->getTitle(),
                'url'   => $this->generateUrl('app_quality_finding', ['id' => $finding->getId()->toRfc4122()]),
            ];
        }
        foreach ($this->actions->findByMeasurements($measured) as $action) {
            $followUps[$action->getMeasurement()?->getId()->toRfc4122() ?? ''][] = [
                'label' => $action->getCode() ?? $action->getDescription(),
                'url'   => $this->generateUrl('app_quality_action', ['id' => $action->getId()->toRfc4122()]),
            ];
        }

        return $this->render('quality/indicator.html.twig', [
            'centre'    => $centre,
            'indicator' => $indicator,
            'year'      => $year,
            'row'       => $row,
            'followUps' => $followUps,
            'canRecord' => $writable && $this->isGranted(QualityVoter::INDICATOR_RECORD, $indicator),
            'canManage' => $this->isGranted(QualityVoter::MANAGE, $centre),
            'writable'  => $writable,
            'canList'   => $this->isGranted(QualityVoter::VIEW_ALL, $centre),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_quality_indicator_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $indicator = $this->requireIndicator($id, $centre, QualityVoter::INDICATOR_VIEW);
        $year      = $this->writableYear($centre);
        $target    = $indicator->targetFor($year);

        $values = [
            'name'           => $indicator->getName(),
            'description'    => $indicator->getDescription() ?? '',
            'section'        => $indicator->getSection()?->getId()->toRfc4122() ?? '',
            'unit'           => $indicator->getUnit() ?? '',
            'direction'      => $indicator->isHigherBetter() ? 'higher' : 'lower',
            'responsible'    => ResponsibleChoices::value($indicator->getResponsibleTeacher(), $indicator->getResponsibleProfile()),
            'active'         => $indicator->isActive() ? '1' : '',
            'calendar'       => $target?->getCalendar()?->getId()->toRfc4122() ?? '',
            'target'         => self::number($target?->getTarget()),
            'alertThreshold' => self::number($target?->getAlertThreshold()),
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'quality_indicator');
            [$values, $errors, $data] = $this->readIndicatorForm($request, $centre, $year, $indicator);
            if ($data !== null) {
                $this->service->saveIndicator($centre, $indicator, $year, $data);
                $this->addFlash('success', $this->t('indicator.flash.saved'));

                return $this->redirectToRoute('app_quality_indicator', ['id' => $id]);
            }
        }

        return $this->renderIndicatorForm($centre, $year, $indicator, $values, $errors);
    }

    /** Records (or corrects) the value of one of the indicator's periods of the active year. */
    #[Route('/{id}/periodos/{periodId}', name: 'app_quality_measurement_record', requirements: ['id' => Requirement::UUID, 'periodId' => Requirement::UUID], methods: ['POST'])]
    public function record(string $id, string $periodId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $indicator = $this->requireIndicator($id, $centre, QualityVoter::INDICATOR_RECORD);
        $this->checkToken($request, 'quality_measurement_' . $id);
        $year   = $this->writableYear($centre);
        $period = null;
        foreach ($indicator->targetFor($year)?->getCalendar()?->getPeriods() ?? [] as $candidate) {
            if ($candidate->getId()->toRfc4122() === $periodId) {
                $period = $candidate;
            }
        }
        if ($period === null) {
            throw $this->createNotFoundException();
        }

        $value = self::parseNumber($request->request->getString('value'));
        if ($value === null) {
            $this->addFlash('error', $this->t('indicator.error.value'));
        } else {
            $measurement = $this->service->record($indicator, $period, $value, $request->request->getString('notes'), $this->teacher());
            // Off target: in red (the layout shows success and error), so it isn't missed.
            $this->addFlash($measurement->status() === IndicatorStatus::OffTarget ? 'error' : 'success', $this->t('indicator.flash.recorded.' . $measurement->status()->value));
        }

        return $this->redirectToRoute('app_quality_indicator', ['id' => $id, '_fragment' => 'periodo-' . $periodId]);
    }

    /** An off-target value that needs nothing done, and why. */
    #[Route('/mediciones/{id}/descartar', name: 'app_quality_measurement_dismiss', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function dismiss(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $measurement = $this->requireMeasurementToReview($id, $centre, $request);
        $note        = trim($request->request->getString('note'));
        if ($note === '') {
            $this->addFlash('error', $this->t('indicator.error.dismiss_note'));
        } else {
            $this->service->dismiss($measurement, $this->teacher(), $note);
            $this->addFlash('success', $this->t('indicator.flash.dismissed'));
        }

        return $this->redirectToRoute('app_quality_indicator', ['id' => $measurement->getIndicator()->getId()->toRfc4122(), '_fragment' => 'periodo-' . $measurement->getPeriod()->getId()->toRfc4122()]);
    }

    /** Opens a finding from an off-target value, to be classified. */
    #[Route('/mediciones/{id}/no-conformidad', name: 'app_quality_measurement_finding', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function openFinding(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $measurement = $this->requireMeasurementToReview($id, $centre, $request);
        $finding     = $this->service->openFinding($measurement, $this->teacher());
        $this->addFlash('success', $this->t('indicator.flash.finding'));

        return $this->redirectToRoute('app_quality_finding', ['id' => $finding->getId()->toRfc4122()]);
    }

    // ── Measurement calendars ────────────────────────────────────────────────

    #[Route('/calendarios', name: 'app_quality_calendars', methods: ['GET', 'POST'])]
    public function calendars(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $year     = $this->tenantContext->getViewYear($centre);
        $writable = !$this->tenantContext->isViewingNonActiveYear($centre);

        $errors = [];
        $values = ['name' => '', 'template' => 'evaluations'];
        if ($request->isMethod('POST')) {
            $year = $this->writableYear($centre);
            $this->checkToken($request, 'quality_calendar_new');
            $values = ['name' => trim($request->request->getString('name')), 'template' => $request->request->getString('template')];
            if ($values['name'] === '') {
                $errors['name'] = $this->t('calendar.error.name');
            }
            if (!\in_array($values['template'], [...MeasurementCalendarTemplates::KEYS, 'empty'], true)) {
                $errors['template'] = $this->t('calendar.error.template');
            }
            if ($errors === []) {
                $calendar = $this->service->createCalendar($centre, $year, $values['name'], $values['template'] === 'empty' ? null : $values['template']);
                $this->addFlash('success', $this->t('calendar.flash.added'));

                return $this->redirectToRoute('app_quality_calendar', ['id' => $calendar->getId()->toRfc4122()]);
            }
        }

        $calendars = $year === null ? [] : $this->calendars->findByYear($year);
        // Offered to start a year off, while it has no calendars of its own yet.
        $previous = $year === null || $calendars !== [] ? null : $this->board->previousYear($centre, $year);

        return $this->render('quality/calendars.html.twig', [
            'centre'    => $centre,
            'year'      => $year,
            'calendars' => $calendars,
            'previous'  => $previous !== null && $this->calendars->findByYear($previous) !== [] ? $previous : null,
            'templates' => MeasurementCalendarTemplates::KEYS,
            'writable'  => $writable,
            'values'    => $values,
            'errors'    => $errors,
            'usage'     => $year === null ? [] : $this->calendarUsage($centre, $year),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/calendarios/copiar', name: 'app_quality_calendars_copy', methods: ['POST'])]
    public function copyCalendars(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $this->checkToken($request, 'quality_calendars_copy');
        $year     = $this->writableYear($centre);
        $previous = $this->board->previousYear($centre, $year);
        if ($previous === null) {
            throw $this->createNotFoundException();
        }
        $copied = $this->service->copyYear($centre, $previous, $year);
        $this->addFlash('success', $this->translator->trans('calendar.flash.copied', ['%calendars%' => $copied['calendars'], '%targets%' => $copied['targets'], '%year%' => $previous->getName()], 'quality'));

        return $this->redirectToRoute('app_quality_calendars');
    }

    #[Route('/calendarios/{id}', name: 'app_quality_calendar', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function calendar(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $calendar = $this->calendars->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $active   = $centre->getActiveAcademicYear();
        $writable = $active !== null && $calendar->getAcademicYear()->getId()->equals($active->getId())
            && !$this->tenantContext->isViewingNonActiveYear($centre);

        $rows = [];
        foreach ($calendar->getPeriods() as $period) {
            $rows[] = ['id' => $period->getId()->toRfc4122(), 'name' => $period->getName(), 'start' => $period->getStartDate()->format('Y-m-d'), 'end' => $period->getEndDate()->format('Y-m-d')];
        }
        $name   = $calendar->getName();
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$writable) {
                throw $this->createAccessDeniedException();
            }
            $this->checkToken($request, 'quality_calendar_' . $id);
            [$name, $rows, $errors, $periods] = $this->readCalendarForm($request, $calendar);
            if ($periods !== null) {
                $this->service->saveCalendar($calendar, $name, $periods);
                $this->addFlash('success', $this->t('calendar.flash.saved'));

                return $this->redirectToRoute('app_quality_calendar', ['id' => $id]);
            }
        }

        return $this->render('quality/calendar.html.twig', [
            'centre'   => $centre,
            'calendar' => $calendar,
            'name'     => $name,
            'rows'     => $rows,
            'errors'   => $errors,
            'writable' => $writable,
            'usage'    => $this->calendarUsage($centre, $calendar->getAcademicYear())[$id] ?? [],
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/calendarios/{id}/eliminar', name: 'app_quality_calendar_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deleteCalendar(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $calendar = $this->calendars->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $this->checkToken($request, 'quality_calendar_' . $id);
        $this->writableYear($centre);
        if (($this->calendarUsage($centre, $calendar->getAcademicYear())[$id] ?? []) !== []) {
            $this->addFlash('error', $this->t('calendar.error.in_use'));

            return $this->redirectToRoute('app_quality_calendar', ['id' => $id]);
        }
        $this->service->deleteCalendar($calendar);
        $this->addFlash('success', $this->t('calendar.flash.deleted'));

        return $this->redirectToRoute('app_quality_calendars');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: ?array{name: string, description: ?string, section: ?\App\Entity\DocumentSection, unit: ?string, higherIsBetter: bool, teacher: ?Teacher, profile: ?\App\Entity\SpecificProfile, active: bool, calendar: ?MeasurementCalendar, target: ?float, alertThreshold: ?float}}
     */
    private function readIndicatorForm(Request $request, EducationalCentre $centre, AcademicYear $year, ?Indicator $editing): array
    {
        $values = [];
        foreach (['name', 'description', 'section', 'unit', 'direction', 'responsible', 'active', 'calendar', 'target', 'alertThreshold'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = $this->t('indicator.error.name');
        }
        $section     = $values['section'] === '' ? null : $this->sections->findByIdAndCentre($values['section'], $centre);
        $responsible = $this->responsibles->resolve($values['responsible'], $centre, $this->teacher(), $editing?->getResponsibleTeacher());
        if ($responsible === null) {
            $errors['responsible'] = $this->t('classify.error.responsible');
        }
        $calendar = null;
        if ($values['calendar'] !== '') {
            $calendar = $this->calendars->findByIdAndCentre($values['calendar'], $centre);
            if ($calendar === null || !$calendar->getAcademicYear()->getId()->equals($year->getId())) {
                $errors['calendar'] = $this->t('indicator.error.calendar');
                $calendar           = null;
            }
        }
        $higher    = $values['direction'] !== 'lower';
        $target    = self::parseNumber($values['target']);
        $threshold = self::parseNumber($values['alertThreshold']);
        if ($values['target'] !== '' && $target === null) {
            $errors['target'] = $this->t('indicator.error.number');
        }
        if ($values['alertThreshold'] !== '' && $threshold === null) {
            $errors['alertThreshold'] = $this->t('indicator.error.number');
        } elseif ($threshold !== null && $target === null) {
            $errors['alertThreshold'] = $this->t('indicator.error.threshold_without_target');
        } elseif ($threshold !== null && ($higher ? $threshold >= $target : $threshold <= $target)) {
            $errors['alertThreshold'] = $this->t($higher ? 'indicator.error.threshold_below' : 'indicator.error.threshold_above');
        }

        if ($errors !== [] || $responsible === null) {
            return [$values, $errors, null];
        }

        return [$values, $errors, [
            'name'           => $values['name'],
            'description'    => $values['description'],
            'section'        => $section,
            'unit'           => $values['unit'],
            'higherIsBetter' => $higher,
            'teacher'        => $responsible[0],
            'profile'        => $responsible[1],
            'active'         => $editing === null || $values['active'] === '1',
            'calendar'       => $calendar,
            'target'         => $target,
            'alertThreshold' => $threshold,
        ]];
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderIndicatorForm(EducationalCentre $centre, AcademicYear $year, ?Indicator $indicator, array $values, array $errors): Response
    {
        return $this->render('quality/indicator_form.html.twig', [
            'centre'    => $centre,
            'year'      => $year,
            'indicator' => $indicator,
            'values'    => $values,
            'errors'    => $errors,
            'sections'  => $this->sectionChoices->choices($this->teacher(), $centre),
            'calendars' => $this->calendars->findByYear($year),
            'teachers'  => $this->responsibles->teachers($centre, $indicator?->getResponsibleTeacher()),
            'profiles'  => $this->responsibles->profiles($centre),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /**
     * The calendar form: its name, the rows as sent (for showing them again), the errors and —
     * when there are none — the periods to save. A row left blank is ignored; one without a name,
     * or with a missing or inverted date, is an error.
     *
     * @return array{0: string, 1: list<array{id: string, name: string, start: string, end: string}>, 2: array<string, string>, 3: ?list<array{id: ?string, name: string, start: \DateTimeImmutable, end: \DateTimeImmutable}>}
     */
    private function readCalendarForm(Request $request, MeasurementCalendar $calendar): array
    {
        $name   = trim($request->request->getString('name'));
        $errors = $name === '' ? ['name' => $this->t('calendar.error.name')] : [];

        $own = [];
        foreach ($calendar->getPeriods() as $period) {
            $own[$period->getId()->toRfc4122()] = true;
        }

        $rows    = [];
        $periods = [];
        foreach ($request->request->all('periods') as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $row = [
                'id'    => \is_string($raw['id'] ?? null) ? $raw['id'] : '',
                'name'  => \is_string($raw['name'] ?? null) ? trim($raw['name']) : '',
                'start' => \is_string($raw['start'] ?? null) ? $raw['start'] : '',
                'end'   => \is_string($raw['end'] ?? null) ? $raw['end'] : '',
            ];
            if (($raw['remove'] ?? '') === '1' || ($row['id'] === '' && $row['name'] === '' && $row['start'] === '' && $row['end'] === '')) {
                continue;
            }
            $rows[] = $row;
            $start  = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['start']);
            $end    = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['end']);
            if ($row['name'] === '' || $start === false || $end === false || $end < $start) {
                $errors['periods'] = $this->t('calendar.error.period');

                continue;
            }
            $periods[] = ['id' => isset($own[$row['id']]) ? $row['id'] : null, 'name' => $row['name'], 'start' => $start, 'end' => $end];
        }
        if ($periods === [] && !isset($errors['periods'])) {
            $errors['periods'] = $this->t('calendar.error.no_periods');
        }

        return [$name, $rows, $errors, $errors === [] ? $periods : null];
    }

    /**
     * Which indicators use each of $year's calendars, by calendar id.
     *
     * @return array<string, list<Indicator>>
     */
    private function calendarUsage(EducationalCentre $centre, AcademicYear $year): array
    {
        $usage = [];
        foreach ($this->indicators->findByCentre($centre) as $indicator) {
            $calendar = $indicator->targetFor($year)?->getCalendar();
            if ($calendar !== null) {
                $usage[$calendar->getId()->toRfc4122()][] = $indicator;
            }
        }

        return $usage;
    }

    /** The active year, when it's the one being viewed: nothing is written in a past one. */
    private function writableYear(EducationalCentre $centre): AcademicYear
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null || $this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException();
        }

        return $year;
    }

    private function requireIndicator(string $id, EducationalCentre $centre, string $attribute): Indicator
    {
        $indicator = $this->indicators->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted($attribute, $indicator);

        return $indicator;
    }

    /** A value of the centre's to decide on, for whoever manages "Mejora continua". */
    private function requireMeasurementToReview(string $id, EducationalCentre $centre, Request $request): Measurement
    {
        $this->denyAccessUnlessGranted(QualityVoter::MANAGE, $centre);
        $measurement = $this->measurements->findByIdAndCentre($id, $centre) ?? throw $this->createNotFoundException();
        $this->checkToken($request, 'quality_review_' . $id);

        return $measurement;
    }

    /** "87,5" or "87.5" → 87.5; anything else → null. */
    private static function parseNumber(string $text): ?float
    {
        $text = str_replace([' ', ','], ['', '.'], trim($text));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** 87.5 → "87,5" for a form field; null → "". */
    private static function number(?float $value): string
    {
        return $value === null ? '' : Indicator::number($value);
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'quality');
    }
}
