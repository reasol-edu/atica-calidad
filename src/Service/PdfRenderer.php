<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\WatermarkText;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class PdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly PdfTemplateResolver $templateResolver,
    ) {}

    /**
     * Renders a Twig template to a PDF response via mPDF, with a shared running
     * header/footer (pdf/_header.html.twig, pdf/_footer.html.twig).
     *
     * @param array<string, mixed>                                     $context        Must include 'centre' (EducationalCentre); merged into header/footer/content.
     * @param PdfHeader|null                                           $header         Custom header content and top margin; falls back to pdfTitle / centre name.
     * @param bool                                                     $draftWatermark Shows a diagonal "BORRADOR" watermark on every page; used while the report/sanction hasn't been notified to the family yet.
     * @param 'P'|'L'                                                  $orientation    'P' (portrait, default) or 'L' (landscape).
     * @param 'incident'|'sanction'|'group_stats'|'guard_duty'|null    $reportType     Together with $centre, resolves and stamps the configured PDF template as the background of every page (see PdfTemplateResolver).
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
    ): Response {
        $context += [
            'pdfTitle'       => $title,
            'pdfGeneratedAt' => $this->clock->now(),
            'headerLeft'     => $header?->leftHtml,
            'headerRight'    => $header?->rightHtml,
        ];

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'orientation'   => $orientation,
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => $header->marginTopMm ?? 22,
            'margin_bottom' => 18,
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
                    'dejavusans',
                ));
                $mpdf->showWatermarkText = true;
            }

            $mpdf->SetHTMLHeader($this->twig->render('pdf/_header.html.twig', $context));
            $mpdf->SetHTMLFooter($this->twig->render('pdf/_footer.html.twig', $context));
            $mpdf->WriteHTML($this->twig->render($template, $context));

            $content = $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            if ($templatePath !== null) {
                @unlink($templatePath);
            }
        }

        if (!is_string($content)) {
            throw new \RuntimeException('mPDF no devolvió el contenido del PDF esperado.');
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
     * Resuelve la plantilla PDF aplicable, la escribe a un fichero temporal
     * (SetDocTemplate necesita una ruta real) y la fija como fondo de cada
     * página generada. Devuelve la ruta del temporal para su limpieza posterior,
     * o null si no hay ninguna plantilla configurada.
     *
     * @param 'incident'|'sanction'|'group_stats'|'guard_duty' $reportType
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
