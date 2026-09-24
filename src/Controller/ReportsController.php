<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\Teacher;
use App\Model\ActivityStatusReportRow;
use App\Model\DocumentMasterListRow;
use App\Model\ReadAcknowledgementStatus;
use App\Repository\DocumentRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\FindingRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityStatusReportBuilder;
use App\Service\DocumentMasterListBuilder;
use App\Service\DocumentReviewSchedule;
use App\Service\PdfRenderer;
use App\Service\ReadAcknowledgementService;
use App\Service\XlsxExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
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
 * - who has read the documents that require it (ReadAcknowledgementService).
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
        private readonly ReadAcknowledgementService $readAcknowledgements,
        private readonly DocumentRepository $documents,
        private readonly FindingRepository $findingRepository,
        private readonly PdfRenderer $pdf,
        private readonly XlsxExporter $xlsx,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('', name: 'app_reports_index')]
    public function index(string $centreId): Response
    {
        $centre     = $this->requireCentre($centreId);
        $reviewsDue = $this->masterList->reviewsDue($centre);

        return $this->render('reports/index.html.twig', [
            'centre'          => $centre,
            'documentCount'   => \count($this->masterList->build($centre)),
            'reviewsDueCount' => \count($reviewsDue),
            'reviewsOverdue'  => \count(array_filter($reviewsDue, static fn (DocumentMasterListRow $r): bool => $r->reviewState === DocumentReviewSchedule::OVERDUE)),
            'activityCount'   => \count($this->activityStatus->build($centre)),
            'readAckCount'    => \count($this->documents->findRequiringReadAcknowledgementByCentre($centre)),
            'findingCounts'   => $this->findingRepository->countByStatus($centre),
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

        $headers = array_map(fn (string $key): string => $this->t('activity_status.col.' . $key), ['category', 'activity', 'opens', 'deadline', 'kind', 'expected', 'delivered', 'done', 'in_review', 'rejected', 'percent']);

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
                $r->donePercentage(),
            ],
            $rows,
        ));
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
     * @param 'document_master_list'|'document_reviews'|'activity_status'|'read_acknowledgements'|'findings' $reportType
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
