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
 * @phpstan-type ReportType 'document_master_list'|'document_reviews'|'activity_status'|'read_acknowledgements'|'findings'|'improvement_plan'|'indicators'|'audit_program'|'audit_report'|'management_review'|'printable_calendar'
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
     * Renders a Twig template to a PDF response via mPDF, with a shared running
     * header/footer (pdf/_header.html.twig, pdf/_footer.html.twig) — unless $showHeader/
     * $showFooter say to skip one, freeing up its margin for content instead.
     *
     * @param array<string, mixed> $context        Must include 'centre' (EducationalCentre); merged into header/footer/content.
     * @param PdfHeader|null       $header         Custom header content and top margin; falls back to pdfTitle / centre name.
     * @param bool                 $draftWatermark Shows a diagonal "BORRADOR" watermark on every page.
     * @param 'P'|'L'              $orientation    'P' (portrait, default) or 'L' (landscape).
     * @param ReportType|null      $reportType     Together with $centre, resolves and stamps the configured PDF template as the background of every page (see PdfTemplateResolver).
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
    ): Response {
        $context += [
            'pdfTitle'       => $title,
            'pdfGeneratedAt' => $this->clock->now(),
            'headerLeft'     => $header?->leftHtml,
            'headerRight'    => $header?->rightHtml,
        ];

        $mpdf = new Mpdf($this->fontConfig() + [
            'format'        => 'A4',
            'orientation'   => $orientation,
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => $showHeader ? ($header->marginTopMm ?? 22) : 12,
            'margin_bottom' => $showFooter ? 18 : 10,
            'margin_header' => 8,
            'margin_footer' => 8,
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

            if ($showHeader) {
                $mpdf->SetHTMLHeader($this->twig->render('pdf/_header.html.twig', $context));
            }
            if ($showFooter) {
                $mpdf->SetHTMLFooter($this->twig->render('pdf/_footer.html.twig', $context));
            }
            $mpdf->WriteHTML($this->twig->render($template, $context));

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
