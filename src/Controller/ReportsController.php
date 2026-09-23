<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Model\ActivityStatusReportRow;
use App\Model\DocumentMasterListRow;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityStatusReportBuilder;
use App\Service\DocumentMasterListBuilder;
use App\Service\DocumentReviewSchedule;
use App\Service\PdfRenderer;
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
 * - how every activity stands this academic year (ActivityStatusReportBuilder).
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
     * @param 'document_master_list'|'document_reviews'|'activity_status' $reportType
     * @param array<string, mixed>                                        $context
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
