<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\Teacher;
use App\Model\ActivityComplianceRow;
use App\Model\ActivityStatusReportRow;
use App\Model\DocumentMasterListRow;
use App\Model\ReadAcknowledgementStatus;
use App\Repository\AcademicYearRepository;
use App\Repository\DocumentRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityComplianceReportBuilder;
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivityStatusReportBuilder;
use App\Service\DocumentMasterListBuilder;
use App\Service\DocumentReviewSchedule;
use App\Service\IndicatorBoardBuilder;
use App\Service\PdfRenderer;
use App\Service\ReadAcknowledgementService;
use App\Service\XlsxExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Informes": reports on the quality system as a whole, each available as a PDF (with the
 * centre's letterhead template, see PdfRenderer) or an Excel sheet:
 *
 * - the document master list (DocumentMasterListBuilder);
 * - documents whose review is overdue or coming up (DocumentMasterListBuilder::reviewsDue());
 * - how every activity stands this academic year (ActivityStatusReportBuilder);
 * - who has read the documents that require it (ReadAcknowledgementService);
 * - the nonconformity log, each year's improvement plan and its indicators board ("Mejora continua").
 *
 * For the centre's admins, quality managers and internal auditors (EducationalCentreVoter::REPORTS),
 * who can already see every document.
 */
#[Route('/centro/{centreId}/informes')]
class ReportsController extends AbstractController
{
    private const string FORMATS = 'pdf|xlsx';

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly DocumentMasterListBuilder $masterList,
        private readonly ActivityStatusReportBuilder $activityStatus,
        private readonly ActivityComplianceReportBuilder $compliance,
        private readonly ReadAcknowledgementService $readAcknowledgements,
        private readonly DocumentRepository $documents,
        private readonly FindingRepository $findingRepository,
        private readonly ImprovementActionRepository $actionRepository,
        private readonly AcademicYearRepository $academicYears,
        private readonly IndicatorBoardBuilder $indicatorBoard,
        private readonly PdfRenderer $pdf,
        private readonly XlsxExporter $xlsx,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('', name: 'app_reports_index')]
    public function index(string $centreId): Response
    {
        $centre     = $this->requireCentre($centreId);
        $rows       = $this->masterList->build($centre);
        $reviewsDue = $this->masterList->reviewsDue($centre, $rows);

        return $this->render('reports/index.html.twig', [
            'centre'          => $centre,
            'documentCount'   => \count($rows),
            'reviewsDueCount' => \count($reviewsDue),
            'reviewsOverdue'  => \count(array_filter($reviewsDue, static fn (DocumentMasterListRow $r): bool => $r->reviewState === DocumentReviewSchedule::OVERDUE)),
            'activityCount'   => \count($this->activityStatus->build($centre)),
            'readAckCount'    => \count($this->documents->findRequiringReadAcknowledgementByCentre($centre)),
            'findingCounts'   => $this->findingRepository->countByStatus($centre),
            'planActions'     => $centre->getActiveAcademicYear() === null ? [] : $this->actionRepository->findPlan($centre, $centre->getActiveAcademicYear()),
            'indicatorGroups' => $centre->getActiveAcademicYear() === null ? [] : $this->indicatorBoard->board($centre, $centre->getActiveAcademicYear()),
            'reviewHorizon'   => DocumentMasterListBuilder::REVIEW_HORIZON_DAYS,
        ]);
    }

    #[Route('/listado-maestro.{_format}', name: 'app_reports_master_list', requirements: ['_format' => self::FORMATS])]
    public function masterList(string $centreId, string $_format): Response
    {
        $centre = $this->requireCentre($centreId);
        $rows   = $this->masterList->build($centre);

        return $_format === 'pdf'
            ? $this->pdfResponse('reports/pdf/master_list.html.twig', 'document_master_list', 'master_list', $centre, ['rows' => $rows])
            : $this->xlsx->createResponse($this->filename('master_list', 'xlsx'), $this->documentHeaders(), $this->documentCells($rows));
    }

    #[Route('/revisiones-de-documentos.{_format}', name: 'app_reports_document_reviews', requirements: ['_format' => self::FORMATS])]
    public function documentReviews(string $centreId, string $_format): Response
    {
        $centre = $this->requireCentre($centreId);
        $rows   = $this->masterList->reviewsDue($centre);

        return $_format === 'pdf'
            ? $this->pdfResponse('reports/pdf/document_reviews.html.twig', 'document_reviews', 'document_reviews', $centre, [
                'rows'    => $rows,
                'horizon' => DocumentMasterListBuilder::REVIEW_HORIZON_DAYS,
            ])
            : $this->xlsx->createResponse($this->filename('document_reviews', 'xlsx'), $this->documentHeaders(), $this->documentCells($rows));
    }

    #[Route('/estado-de-actividades.{_format}', name: 'app_reports_activity_status', requirements: ['_format' => self::FORMATS])]
    public function activityStatus(string $centreId, string $_format): Response
    {
        $centre = $this->requireCentre($centreId);
        $rows   = $this->activityStatus->build($centre);

        if ($_format === 'pdf') {
            return $this->pdfResponse('reports/pdf/activity_status.html.twig', 'activity_status', 'activity_status', $centre, ['rows' => $rows]);
        }

        $headers = array_map(fn (string $key): string => $this->t('activity_status.col.' . $key), ['category', 'activity', 'opens', 'deadline', 'kind', 'expected', 'delivered', 'done', 'in_review', 'rejected', 'overdue', 'percent']);

        return $this->xlsx->createResponse($this->filename('activity_status', 'xlsx'), $headers, array_map(
            fn (ActivityStatusReportRow $r): array => [
                $r->categoryPath,
                $r->title,
                $r->startsAt->format('d/m/Y'),
                $r->deadline->format('d/m/Y'),
                $this->t($r->withSubmissions ? 'activity_status.kind.submissions' : 'activity_status.kind.manual'),
                $r->expected,
                $r->delivered,
                $r->done,
                $r->inReview,
                $r->rejected,
                $r->overdue,
                $r->donePercentage(),
            ],
            $rows,
        ));
    }

    /**
     * How each activity went in a chosen academic year, against another one if asked
     * (ActivityComplianceReportBuilder) — on screen, with the same figures as PDF and Excel.
     */
    #[Route('/cumplimiento-de-actividades', name: 'app_reports_compliance')]
    public function compliance(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        [$cycle, $compare, $available] = $this->complianceCycles($centre, $request);

        return $this->render('reports/compliance.html.twig', [
            'centre'    => $centre,
            'cycle'     => $cycle,
            'compare'   => $compare,
            'available' => $available,
            'labels'    => array_combine($available, array_map(ActivityDeadlineChecker::academicYearLabel(...), $available)),
            'rows'      => $this->compliance->build($centre, $cycle, $compare),
        ]);
    }

    #[Route('/cumplimiento-de-actividades.{_format}', name: 'app_reports_compliance_export', requirements: ['_format' => self::FORMATS])]
    public function complianceExport(string $centreId, string $_format, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        [$cycle, $compare] = $this->complianceCycles($centre, $request);
        $rows = $this->compliance->build($centre, $cycle, $compare);

        if ($_format === 'pdf') {
            // Printed on the activity-status letterhead: the PDF templates are configured per report type.
            return $this->pdfResponse('reports/pdf/activity_compliance.html.twig', 'activity_status', 'compliance', $centre, [
                'rows'         => $rows,
                'compare'      => $compare,
                'cycleLabel'   => ActivityDeadlineChecker::academicYearLabel($cycle),
                'compareLabel' => $compare === null ? '' : ActivityDeadlineChecker::academicYearLabel($compare),
            ]);
        }

        $headers = [$this->t('compliance.col.category'), $this->t('compliance.col.activity'), $this->t('compliance.col.kind'), $this->t('compliance.col.expected'), $this->t('compliance.col.done'), $this->t('compliance.col.percent'), $this->t('compliance.col.on_time')];
        if ($compare !== null) {
            array_push($headers, $this->t('compliance.col.compare_percent') . ' ' . ActivityDeadlineChecker::academicYearLabel($compare), $this->t('compliance.col.delta'));
        }

        return $this->xlsx->createResponse($this->filename('compliance', 'xlsx'), $headers, array_map(
            fn (ActivityComplianceRow $r): array => array_merge([
                $r->categoryPath,
                $r->title,
                $this->t($r->withSubmissions ? 'activity_status.kind.submissions' : 'activity_status.kind.manual'),
                $r->current->expected,
                $r->current->done,
                $r->current->percentage(),
                $r->current->onTimePercentage(),
            ], $compare === null ? [] : [$r->previous?->percentage(), $r->delta()]),
            $rows,
        ));
    }

    /**
     * The chosen year and the one to compare it with, from ?curso= and ?comparar=: the current one
     * and the next older one that has something by default, "0" for no comparison.
     *
     * @return array{0: int, 1: ?int, 2: list<int>} year, comparison year, every year there is data for
     */
    private function complianceCycles(EducationalCentre $centre, Request $request): array
    {
        $available = $this->compliance->availableCycles($centre);
        $asked     = $request->query->getInt('curso');
        $cycle     = \in_array($asked, $available, true) ? $asked : $this->compliance->currentCycle($centre);

        if ($request->query->has('comparar')) {
            $compareAsked = $request->query->getInt('comparar');
            $compare      = $compareAsked !== $cycle && \in_array($compareAsked, $available, true) ? $compareAsked : null;
        } else {
            $older   = array_values(array_filter($available, static fn (int $y): bool => $y < $cycle));
            $compare = $older[0] ?? null;
        }

        return [$cycle, $compare, $available];
    }

    /**
     * Who has read, and who hasn't, the version in force of every document that requires it
     * (ReadAcknowledgementService), in tree order.
     */
    #[Route('/acuses-de-lectura.{_format}', name: 'app_reports_read_acknowledgements', requirements: ['_format' => self::FORMATS])]
    public function readAcknowledgements(string $centreId, string $_format): Response
    {
        $centre   = $this->requireCentre($centreId);
        $statuses = array_values($this->readAcknowledgements->statusOf($this->documents->findRequiringReadAcknowledgementByCentre($centre)));

        if ($_format === 'pdf') {
            return $this->pdfResponse('reports/pdf/read_acknowledgements.html.twig', 'read_acknowledgements', 'read_acknowledgements', $centre, [
                'statuses' => $statuses,
                'paths'    => array_map(fn (ReadAcknowledgementStatus $s): string => $this->sectionPath($s->document), $statuses),
            ]);
        }

        $headers = array_map(fn (string $key): string => $this->t('read_acknowledgements.col.' . $key), ['section', 'folder', 'document', 'version', 'read', 'total', 'percent', 'pending']);

        return $this->xlsx->createResponse($this->filename('read_acknowledgements', 'xlsx'), $headers, array_map(
            fn (ReadAcknowledgementStatus $s): array => [
                $this->sectionPath($s->document),
                $s->document->getFolder()->getName(),
                $s->document->getName(),
                $s->document->getActiveRevision()?->getVersion(),
                $s->readCount(),
                $s->total(),
                $s->total() > 0 ? (int) round(100 * $s->readCount() / $s->total()) : 100,
                implode('; ', array_map(static fn (Teacher $t): string => $t->getName()->getLastName() . ', ' . $t->getName()->getFirstName(), $s->pending)),
            ],
            $statuses,
        ));
    }

    /**
     * Every finding of the centre but the discarded ones, most recent first: kind, process,
     * status, dates, actions and effectiveness — the nonconformity log an audit asks for.
     */
    #[Route('/no-conformidades.{_format}', name: 'app_reports_findings', requirements: ['_format' => self::FORMATS])]
    public function findings(string $centreId, string $_format): Response
    {
        $centre   = $this->requireCentre($centreId);
        $findings = array_values(array_filter(
            $this->findingRepository->createFilteredQuery($centre)->getResult(),
            static fn (Finding $f): bool => $f->getStatus() !== FindingStatus::Discarded,
        ));

        if ($_format === 'pdf') {
            return $this->pdfResponse('reports/pdf/findings.html.twig', 'findings', 'findings', $centre, ['findings' => $findings]);
        }

        $headers = array_map(fn (string $key): string => $this->t('findings.col.' . $key), ['code', 'title', 'kind', 'section', 'origin', 'status', 'reported', 'actions', 'closed', 'effective']);

        return $this->xlsx->createResponse($this->filename('findings', 'xlsx'), $headers, array_map(
            fn (Finding $f): array => [
                $f->getCode(),
                $f->getTitle(),
                $f->getKind() === null ? null : $this->translator->trans('kind.' . $f->getKind()->value, [], 'quality')
                    . ($f->getSeverity() === null ? '' : ' · ' . $this->translator->trans('severity.' . $f->getSeverity()->value, [], 'quality')),
                $f->getSection()?->getName(),
                $this->translator->trans('origin.' . $f->getOrigin()->value, [], 'quality'),
                $this->translator->trans('status.' . $f->getStatus()->value, [], 'quality'),
                $f->getReportedAt()->format('d/m/Y'),
                ($f->getActions()->count() - $f->countPendingActions()) . '/' . $f->getActions()->count(),
                $f->getClosedAt()?->format('d/m/Y'),
                match ($f->isEffective()) {
                    true    => $this->t('findings.effective.yes'),
                    false   => $this->t('findings.effective.no'),
                    default => null,
                },
            ],
            $findings,
        ));
    }

    /**
     * An academic year's improvement plan — the active one, or ?curso= — soonest due first: its
     * preventive and improvement actions with their goal, process, responsible, due date, status
     * and what was done.
     */
    #[Route('/plan-de-mejora.{_format}', name: 'app_reports_improvement_plan', requirements: ['_format' => self::FORMATS])]
    public function improvementPlan(string $centreId, string $_format, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $yearId = $request->query->getString('curso');
        $year   = $yearId !== '' ? $this->academicYears->findByCentreAndId($centre, $yearId) : $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }
        $actions = $this->actionRepository->findPlan($centre, $year);
        $today   = $this->clock->now();

        if ($_format === 'pdf') {
            return $this->pdfResponse('reports/pdf/improvement_plan.html.twig', 'improvement_plan', 'improvement_plan', $centre, [
                'actions' => $actions,
                'year'    => $year,
                'today'   => $today,
            ]);
        }

        $headers = array_map(fn (string $key): string => $this->t('improvement_plan.col.' . $key), ['code', 'type', 'action', 'goal', 'section', 'responsible', 'due', 'status', 'done', 'result']);

        return $this->xlsx->createResponse($this->filename('improvement_plan', 'xlsx'), $headers, array_map(
            fn (ImprovementAction $a): array => [
                $a->getCode(),
                $this->translator->trans('action_type.' . $a->getType()->value, [], 'quality'),
                $a->getDescription(),
                $a->getGoal(),
                $a->getSection()?->getName(),
                $a->getResponsibleTeacher() !== null
                    ? $a->getResponsibleTeacher()->getName()->getFirstName() . ' ' . $a->getResponsibleTeacher()->getName()->getLastName()
                    : $a->getResponsibleProfile()?->getName(),
                $a->getDueDate()?->format('d/m/Y'),
                $this->translator->trans($a->isOverdue($today) ? 'action_status.overdue' : 'action_status.' . $a->getStatus()->value, [], 'quality'),
                $a->getDoneAt()?->format('d/m/Y'),
                $a->getResult(),
            ],
            $actions,
        ));
    }

    /**
     * The indicators board of an academic year — the active one, or ?curso= — by process: each
     * indicator's target, its value for every period and how it stands, and last year's value.
     * The Excel has a row per indicator and period, to work with the data.
     */
    #[Route('/indicadores.{_format}', name: 'app_reports_indicators', requirements: ['_format' => self::FORMATS])]
    public function indicators(string $centreId, string $_format, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $yearId = $request->query->getString('curso');
        $year   = $yearId !== '' ? $this->academicYears->findByCentreAndId($centre, $yearId) : $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }
        $groups = $this->indicatorBoard->board($centre, $year);

        if ($_format === 'pdf') {
            return $this->pdfResponse('reports/pdf/indicators.html.twig', 'indicators', 'indicators', $centre, ['groups' => $groups, 'year' => $year]);
        }

        $headers = array_map(fn (string $key): string => $this->t('indicators.col.' . $key), ['process', 'indicator', 'unit', 'target', 'threshold', 'period', 'value', 'status', 'recorded']);
        $rows    = [];
        foreach ($groups as $process => $indicatorRows) {
            foreach ($indicatorRows as $row) {
                foreach ($row->cells as $cell) {
                    $status = $cell->status();
                    $rows[] = [
                        $process,
                        $row->indicator->getName(),
                        $row->indicator->getUnit(),
                        $row->target?->getTarget(),
                        $row->target?->getAlertThreshold(),
                        $cell->period->getName(),
                        $cell->measurement?->getValue(),
                        $status === null ? null : $this->translator->trans('indicator.status.' . $status->value, [], 'quality'),
                        $cell->measurement?->getRecordedAt()->format('d/m/Y'),
                    ];
                }
            }
        }

        return $this->xlsx->createResponse($this->filename('indicators', 'xlsx'), $headers, $rows);
    }

    /** "8. Operación › 8.1 Planificación" for the document's section and its ancestors. */
    private function sectionPath(Document $document): string
    {
        $names = [];
        for ($section = $document->getFolder()->getDocumentSection(); $section !== null; $section = $section->getParent()) {
            array_unshift($names, $section->getName());
        }

        return implode(' › ', $names);
    }

    /**
     * @param 'document_master_list'|'document_reviews'|'activity_status'|'read_acknowledgements'|'findings'|'improvement_plan'|'indicators' $reportType
     * @param array<string, mixed>                                                                $context
     */
    private function pdfResponse(string $template, string $reportType, string $key, EducationalCentre $centre, array $context): Response
    {
        return $this->pdf->render(
            $template,
            $context + ['centre' => $centre, 'reviewSoonDays' => DocumentReviewSchedule::SOON_DAYS],
            $this->t($key . '.title'),
            $this->filename($key, 'pdf'),
            inline: true,
            orientation: 'L',
            centre: $centre,
            reportType: $reportType,
        );
    }

    /** @return list<string> */
    private function documentHeaders(): array
    {
        return array_map(fn (string $key): string => $this->t('master_list.col.' . $key), ['section', 'folder', 'document', 'version', 'version_date', 'status', 'uploaded_by', 'responsibles', 'next_review']);
    }

    /**
     * @param list<DocumentMasterListRow> $rows
     *
     * @return list<list<string|int|null>>
     */
    private function documentCells(array $rows): array
    {
        return array_map(
            fn (DocumentMasterListRow $r): array => [
                $r->sectionPath,
                $r->folderName,
                $r->documentName,
                $r->version,
                $r->versionDate?->format('d/m/Y'),
                $this->t('master_list.status.' . $r->status),
                $r->uploadedBy,
                $r->responsibles,
                $r->nextReviewAt?->format('d/m/Y'),
            ],
            $rows,
        );
    }

    /** ASCII only: the Content-Disposition fallback name can't carry accents. */
    private function filename(string $key, string $extension): string
    {
        return $this->t($key . '.filename') . '-' . $this->clock->now()->format('Y-m-d') . '.' . $extension;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'reports');
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::REPORTS, $centre);

        return $centre;
    }
}
