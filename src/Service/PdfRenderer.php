<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\WatermarkText;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * @phpstan-type ReportType 'document_master_list'|'document_reviews'|'activity_status'|'read_acknowledgements'|'profile_assignments'|'findings'|'improvement_plan'|'indicators'|'audit_program'|'audit_report'|'management_review'
 */
class PdfRenderer
{
    /** mPDF fontdata key: lowercase, no spaces — matches how mPDF's own bundled fonts (dejavusans...) are keyed. */
    private const string FONT_NAME = 'sourcesanspro';

    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly PdfTemplateResolver $templateResolver,
        #[Autowire('%kernel.project_dir%/config/pdf/fonts')]
        private readonly string $fontDir,
    ) {}

    /**
     * How tall a block of HTML is when it is drawn, in millimetres: for the margin that leaves room for a header or footer whose height
     * is not known beforehand (a table the person drew).
     *
     * @param 'P'|'L' $orientation
     */
    public function heightMm(string $html, string $orientation, float $marginLeft, float $marginRight): float
    {
        $mpdf = new Mpdf($this->fontConfig() + [
            'format' => 'A4', 'orientation' => $orientation, 'margin_left' => $marginLeft, 'margin_right' => $marginRight,
            'margin_top' => 0, 'margin_bottom' => 0, 'tempDir' => sys_get_temp_dir(),
        ]);
        $mpdf->AddPage();
        $mpdf->WriteHTML($html);

        $y = $mpdf->y;

        return \is_float($y) || \is_int($y) ? (float) $y : 0.0;
    }

    /**
     * Renders a Twig template to a PDF response via mPDF, with a shared running
     * header/footer (pdf/_header.html.twig, pdf/_footer.html.twig) — unless $showHeader/
     * $showFooter say to skip one, freeing up its margin for content instead.
     *
     * @param array<string, mixed> $context        Must include 'centre' (EducationalCentre); merged into header/footer/content.
     * @param PdfHeader|null       $header         Custom header content and top margin; falls back to pdfTitle / centre name.
     * @param bool                 $draftWatermark Shows a diagonal "BORRADOR" watermark on every page.
     * @param 'P'|'L'              $orientation    'P' (portrait, default) or 'L' (landscape).
     * @param ReportType|null      $reportType     Together with $centre, resolves and stamps the configured PDF template as the background of every page (see PdfTemplateResolver).
     * @param list<array{name: string, mime: string, content: string, description: string}> $embeddedFiles files carried inside the PDF
     * @param list<array{title: string, content: string}>                                   $appendedPdfs  PDFs whose pages go after the document
     */
    public function render(
        string $template,
        array $context,
        string $title,
        string $filename,
        bool $inline = true,
        ?PdfHeader $header = null,
        bool $draftWatermark = false,
        string $orientation = 'P',
        ?EducationalCentre $centre = null,
        ?string $reportType = null,
        bool $showHeader = true,
        bool $showFooter = true,
        ?string $footerHtml = null,
        ?PdfMargins $margins = null,
        array $embeddedFiles = [],
        array $appendedPdfs = [],
    ): Response {
        $context += [
            'pdfTitle'       => $title,
            'pdfGeneratedAt' => $this->clock->now(),
            'header'         => $header,
        ];

        $mpdf = new Mpdf($this->fontConfig() + [
            'format'        => 'A4',
            'orientation'   => $orientation,
            'margin_left'   => $margins->left ?? 15,
            'margin_right'  => $margins->right ?? 15,
            'margin_top'    => $showHeader ? ($header->marginTopMm ?? 22) : 12,
            'margin_bottom' => $margins->bottom ?? ($showFooter ? 18 : 10),
            'margin_header' => $margins->header ?? 8,
            'margin_footer' => $margins->footer ?? 8,
            // A header as tall as its table: the margin grows with it instead of covering the content.
            'setAutoTopMargin' => $showHeader && $header?->stretch === true ? 'stretch' : false,
            'tempDir'       => sys_get_temp_dir(),
        ]);

        $templatePath = null;
        if ($centre !== null && $reportType !== null) {
            $templatePath = $this->applyDocTemplate($mpdf, $reportType, $orientation, $centre);
        }

        try {
            if ($draftWatermark) {
                $mpdf->SetWatermarkText(new WatermarkText(
                    mb_strtoupper($this->translator->trans('pdf.watermark.draft', [], 'admin')),
                    120,
                    45,
                    '#999999',
                    0.15,
                    self::FONT_NAME,
                ));
                $mpdf->showWatermarkText = true;
            }

            $body = $this->twig->render($template, $context);
            if ($showHeader && $header?->firstHtml !== null) {
                // The first page has a header of its own: both are named, shown on their pages, and the body is pushed down by what the
                // first one is taller than the normal one.
                $pages = '<htmlpageheader name="firstPage">' . $header->firstHtml . '</htmlpageheader>'
                    . '<htmlpageheader name="otherPages">' . $this->twig->render('pdf/_header.html.twig', $context) . '</htmlpageheader>'
                    . '<sethtmlpageheader name="firstPage" value="on" show-this-page="1" /><sethtmlpageheader name="otherPages" value="on" />'
                    . ($header->firstExtraMm > 0 ? '<div style="height: ' . round($header->firstExtraMm, 2) . 'mm;"></div>' : '');
                // Inside the <body> of the template when it is a whole document (before its <html> the library ignores them).
                $count = 0;
                $body  = preg_replace('/(<body[^>]*>)/i', '$1' . str_replace('$', '\\$', $pages), $body, 1, $count) ?? $body;
                if ($count === 0) {
                    $body = $pages . $body;
                }
            } elseif ($showHeader) {
                $mpdf->SetHTMLHeader($this->twig->render('pdf/_header.html.twig', $context));
            }
            if ($showFooter) {
                $mpdf->SetHTMLFooter($footerHtml ?? $this->twig->render('pdf/_footer.html.twig', $context));
            }
            $mpdf->WriteHTML($body);
            $this->appendPdfs($mpdf, $appendedPdfs);
            if ($embeddedFiles !== []) {
                // Files carried inside the PDF (the panel of attachments of a PDF reader): the PDF is complete on its own.
                $mpdf->SetAssociatedFiles(array_map(static fn (array $f): array => [
                    'name'           => $f['name'],
                    'mime'           => $f['mime'],
                    'content'        => $f['content'],
                    'description'    => $f['description'],
                    'AFRelationship' => 'Supplement',
                ], $embeddedFiles));
            }

            $content = $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            if ($templatePath !== null) {
                @unlink($templatePath);
            }
        }

        if (!is_string($content)) {
            throw new \RuntimeException('mPDF did not return the expected PDF content.');
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        ));

        return $response;
    }

    /**
     * How many pages a PDF has, when the library can read it (0 when it cannot: some compressed or damaged PDFs). What tells whether its
     * pages can be appended to a document (appendPdfs()).
     */
    public function pageCount(string $content): int
    {
        try {
            $reader = new \setasign\Fpdi\PdfReader\PdfReader(new \setasign\Fpdi\PdfParser\PdfParser(\setasign\Fpdi\PdfParser\StreamReader::createByString($content)));

            return $reader->getPageCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The pages of other PDFs after the document, each at its own size, without the document's header and footer (they have theirs).
     * One that cannot be read is skipped: pageCount() says beforehand which.
     *
     * @param list<array{title: string, content: string}> $pdfs
     */
    private function appendPdfs(Mpdf $mpdf, array $pdfs): void
    {
        foreach ($pdfs as $pdf) {
            try {
                $count = $mpdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf['content']));
                for ($page = 1; $page <= $count; ++$page) {
                    $template = $mpdf->importPage($page);
                    $size     = $mpdf->getTemplateSize($template);
                    if (!is_array($size) || !is_numeric($size['width'] ?? null) || !is_numeric($size['height'] ?? null)) {
                        continue;
                    }
                    $size = ['width' => (float) $size['width'], 'height' => (float) $size['height']];
                    $mpdf->AddPageByArray([
                        'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                        'sheet-size'  => [$size['width'], $size['height']],
                        'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0, 'mgh' => 0, 'mgf' => 0,
                        'ohvalue' => -1, 'ehvalue' => -1, 'ofvalue' => -1, 'efvalue' => -1,
                    ]);
                    if ($page === 1) {
                        $mpdf->Bookmark($pdf['title']);
                    }
                    $mpdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * Registers Source Sans Pro (config/pdf/fonts/) as an mPDF custom font and makes it the
     * document default, so every PDF export uses it without each template having to ask for it —
     * templates/pdf/_styles.html.twig (and the header/footer partials) also name it explicitly in
     * their own font-family, for templates rendered outside the shared body style.
     *
     * @return array<string, mixed>
     */
    private function fontConfig(): array
    {
        $configDefaults = (new ConfigVariables())->getDefaults();
        $fontDefaults   = (new FontVariables())->getDefaults();

        $fontDirs = \is_array($configDefaults) && \is_array($configDefaults['fontDir'] ?? null) ? $configDefaults['fontDir'] : [];
        $fontData = \is_array($fontDefaults) && \is_array($fontDefaults['fontdata'] ?? null) ? $fontDefaults['fontdata'] : [];

        return [
            'fontDir'      => array_merge($fontDirs, [$this->fontDir]),
            'fontdata'     => $fontData + [
                self::FONT_NAME => [
                    'R'  => 'SourceSansPro-Regular.ttf',
                    'B'  => 'SourceSansPro-Bold.ttf',
                    'I'  => 'SourceSansPro-It.ttf',
                    'BI' => 'SourceSansPro-BoldIt.ttf',
                ],
            ],
            'default_font' => self::FONT_NAME,
        ];
    }

    /**
     * Resolves the applicable PDF template, writes it to a temporary file
     * (SetDocTemplate needs a real path) and sets it as the background for every
     * generated page. Returns the temp file's path for later cleanup,
     * or null if no template is configured.
     *
     * @param ReportType $reportType
     */
    private function applyDocTemplate(Mpdf $mpdf, string $reportType, string $orientation, EducationalCentre $centre): ?string
    {
        $resolved = $this->templateResolver->resolve($reportType, $orientation, $centre);
        if ($resolved === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'pdftpl_');
        if ($path === false) {
            return null;
        }

        file_put_contents($path, $resolved->file->getContent());
        $mpdf->SetDocTemplate($path, true);

        return $path;
    }
}
