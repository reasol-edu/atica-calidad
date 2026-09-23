<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ActivityLog;
use App\Repository\ActivityLogRepository;
use App\Repository\EducationalCentreRepository;
use App\Service\PdfHeader;
use App\Service\PdfRenderer;
use App\Service\ProxyConfigurationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/registro-actividad')]
#[IsGranted('ROLE_ADMIN')]
class ActivityLogController extends AbstractController
{
    /** A PDF is for reading, not for bulk: past this many entries, the CSV is the way. */
    public const int PDF_MAX_ROWS = 2000;

    /** Entries hydrated between two EntityManager::clear() while streaming the CSV. */
    private const int CSV_BATCH = 500;

    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly EducationalCentreRepository $centres,
        private readonly EntityManagerInterface $em,
        private readonly PdfRenderer $pdf,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ProxyConfigurationChecker $proxyChecker,
    ) {}

    #[Route('', name: 'app_admin_activity_log')]
    public function index(Request $request): Response
    {
        return $this->render('admin/activity_log/index.html.twig', [
            'proxyWarning' => $this->proxyChecker->check($request),
        ]);
    }

    /**
     * The entries matching the filters of the list (same query parameters as the
     * ActivityLogListComponent props), as a CSV streamed row by row — so it scales to the whole
     * log — or as a PDF of at most PDF_MAX_ROWS entries.
     */
    #[Route('/exportar.{_format}', name: 'app_admin_activity_log_export', requirements: ['_format' => 'csv|pdf'])]
    public function export(Request $request, string $_format): Response
    {
        $filters = [
            'dateFrom'   => $request->query->getString('dateFrom'),
            'dateTo'     => $request->query->getString('dateTo'),
            'userQuery'  => $request->query->getString('userQuery'),
            'centreId'   => Uuid::isValid($request->query->getString('centreId')) ? $request->query->getString('centreId') : '',
            'actionType' => $request->query->getString('actionType'),
            'sort'       => $request->query->getString('sort', 'createdAt'),
            'sortDir'    => $request->query->getString('sortDir', 'desc'),
        ];
        $filename = $this->t('activity_log.export.filename') . '-' . $this->clock->now()->format('Y-m-d-His') . '.' . $_format;

        return $_format === 'csv' ? $this->csv($filters, $filename) : $this->pdfExport($filters, $filename);
    }

    /** @param array<string, string> $filters */
    private function csv(array $filters, string $filename): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($filters): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            // BOM + ";" so that a double click opens it right in a Spanish Excel.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $this->headers(), ';', escape: '');

            $n = 0;
            foreach ($this->logs->createFilteredQuery($filters)->toIterable() as $log) {
                fputcsv($out, array_map(self::defuseFormula(...), $this->cells($log)), ';', escape: '');
                if (++$n % self::CSV_BATCH === 0) {
                    flush();
                    $this->em->clear();
                }
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * Usernames, document names… end up in the log: a cell starting like a formula would run as one
     * when the CSV is opened in a spreadsheet, so it's quoted as text (OWASP "CSV injection").
     */
    private static function defuseFormula(string $cell): string
    {
        return $cell !== '' && str_contains('=+-@' . "\t\r", $cell[0]) ? "'" . $cell : $cell;
    }

    /** @param array<string, string> $filters */
    private function pdfExport(array $filters, string $filename): Response
    {
        $entries   = $this->logs->createFilteredQuery($filters)->setMaxResults(self::PDF_MAX_ROWS + 1)->getResult();
        $truncated = \count($entries) > self::PDF_MAX_ROWS;
        $title     = $this->t('activity_log.title');

        return $this->pdf->render(
            'admin/activity_log/pdf.html.twig',
            [
                'headers'   => $this->headers(),
                'rows'      => array_map($this->cells(...), \array_slice($entries, 0, self::PDF_MAX_ROWS)),
                'truncated' => $truncated,
                'maxRows'   => self::PDF_MAX_ROWS,
                'filters'   => $this->describeFilters($filters),
            ],
            $title,
            $filename,
            header: new PdfHeader(htmlspecialchars($title), '', 22),
            orientation: 'L',
        );
    }

    /** @return list<string> */
    private function headers(): array
    {
        return array_map(
            fn (string $col): string => $this->t('activity_log.list.col.' . $col),
            ['date', 'ip', 'user', 'centre', 'action', 'detail'],
        );
    }

    /** @return list<string> */
    private function cells(ActivityLog $log): array
    {
        $user = $log->getActiveUser();
        $real = $log->getRealUser();

        $userLabel = $user === null ? '' : $user->getName()->getLastName() . ', ' . $user->getName()->getFirstName() . ' (' . $user->getUsername() . ')';
        if ($user !== null && $real !== null && !$real->getId()->equals($user->getId())) {
            $userLabel .= ' — ' . $this->t('activity_log.impersonated_by', ['%user%' => $real->getUsername()]);
        }

        $detail = [];
        if ($log->getRoute() !== null) {
            $detail[] = trim($log->getMethod() . ' ' . $log->getRoute() . ($log->getStatusCode() !== null ? ' · ' . $log->getStatusCode() : ''));
        }
        foreach ($log->getData() ?? [] as $key => $value) {
            if (\in_array($key, ['method', 'status', 'path'], true)) {
                continue;
            }
            $detail[] = $key . ': ' . (\is_array($value) ? implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : (string) json_encode($v), $value)) : (\is_scalar($value) ? (string) $value : (string) json_encode($value)));
        }

        return [
            $log->getCreatedAt()->format('d/m/Y H:i:s'),
            $log->getIp(),
            $userLabel,
            $log->getEducationalCentre()?->getName() ?? '',
            $this->t('activity_log.action.' . $log->getActionType()),
            implode(' | ', $detail),
        ];
    }

    /**
     * @param array<string, string> $filters
     *
     * @return list<string> one "Label: value" line per active filter
     */
    private function describeFilters(array $filters): array
    {
        $lines = [];
        foreach (['dateFrom' => 'date_from', 'dateTo' => 'date_to', 'userQuery' => 'user_label'] as $key => $label) {
            if ($filters[$key] !== '') {
                $value   = \in_array($key, ['dateFrom', 'dateTo'], true) ? $this->formatDate($filters[$key]) : $filters[$key];
                $lines[] = $this->t('activity_log.list.' . $label) . ': ' . $value;
            }
        }
        if ($filters['centreId'] !== '') {
            $lines[] = $this->t('activity_log.list.col.centre') . ': ' . ($this->centres->findById($filters['centreId'])?->getName() ?? $filters['centreId']);
        }
        if ($filters['actionType'] !== '') {
            $lines[] = $this->t('activity_log.list.col.action') . ': ' . $this->t('activity_log.action.' . $filters['actionType']);
        }

        return $lines;
    }

    private function formatDate(string $value): string
    {
        try {
            return new \DateTimeImmutable($value)->format('d/m/Y H:i');
        } catch (\Exception) {
            return $value;
        }
    }

    /** @param array<string, string> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'admin');
    }
}
