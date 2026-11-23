<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Entity\FormModelRevision;
use App\Service\Form\Layout\LayoutHtml;
use App\Service\PdfHeader;
use App\Service\PdfMargins;
use App\Service\PdfRenderer;

/**
 * The PDF of a model (blank, as people will see it) or of a filled document: the stored file of every revision. The definition is
 * resolved once (FormResolver) and a Twig template draws it, with the running header and footer of the model's layout (or the
 * centre's), its orientation and its font scale.
 */
final class FormPdfBuilder
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly FormResolver $resolver,
        private readonly FormLayoutResolver $layouts,
        private readonly LayoutHtml $html,
    ) {}

    /**
     * @param array<string, mixed>  $definition
     * @param array<string, mixed>  $data
     * @param array<string, string> $context    what `{{ctx:…}}` references read (see FormResolver)
     * @param ?string               $modelTitle what the header's {titulo} says (the title of the model; by default `$title`)
     * @param ?\DateTimeImmutable   $revisedAt  the date of the revision of the model ({fecha_revision}; by default today)
     *
     * @return string the PDF content
     */
    public function build(array $definition, array $data, EducationalCentre $centre, string $title, ?string $code, int $modelVersion, bool $blank = false, array $context = [], ?string $modelTitle = null, ?\DateTimeImmutable $revisedAt = null, ?\DateTimeImmutable $approvedAt = null, bool $pendingApproval = false, bool $draft = false): string
    {
        $layout = $this->layouts->resolve($definition, $centre, $this->layouts->values($centre, $modelTitle ?? $title, $code, $modelVersion, $revisedAt ?? new \DateTimeImmutable(), $approvedAt, $pendingApproval));

        // The header, the footer and the first page's header are as tall as the tables drawn in them: they are measured, and the body
        // starts below the header and stops above the footer, with the margins of the layout from the edge of the paper.
        $m           = $layout->margins;
        $orientation = $layout->isLandscape() ? 'L' : 'P';
        // The width of the text: the logos are fitted to the cells of the tables, which share it.
        $contentCm   = (($layout->isLandscape() ? 297 : 210) - $m['left'] - $m['right']) / 10;
        $headerHtml  = $this->html->toPdfHtml($this->html->parseForDocument($layout->headerHtml, $layout->images), 9, $layout->images, $contentCm);
        $footer      = $this->html->toPdfHtml($this->html->parseForDocument($layout->footerHtml, $layout->images), 8, $layout->images, $contentCm);
        $headerMm    = $this->pdf->heightMm($headerHtml, $orientation, $m['left'], $m['right']);
        $footerMm    = $this->pdf->heightMm($footer, $orientation, $m['left'], $m['right']);
        $firstHtml   = null;
        $firstExtra  = 0.0;
        if ($layout->hasFirstHeader()) {
            $firstHtml  = $this->html->toPdfHtml($this->html->parseForDocument($layout->firstHeaderHtml, $layout->images), 9, $layout->images, $contentCm);
            $firstExtra = max(0.0, $this->pdf->heightMm($firstHtml, $orientation, $m['left'], $m['right']) - $headerMm);
        }
        $header  = new PdfHeader($headerHtml, $m['top'] + $headerMm + 4, false, $firstHtml, $firstExtra);
        $margins = new PdfMargins($m['left'], $m['right'], $m['bottom'] + $footerMm + 3, $m['top'], $m['bottom']);

        $response = $this->pdf->render(
            'pdf/form_document.html.twig',
            [
                'centre'    => $centre,
                'title'     => $title,
                'code'      => $code,
                'sections'  => $this->resolver->resolve($definition, $data, $context),
                'blank'     => $blank,
                'fontScale' => $layout->fontScale,
                // The header already says the title of the model: the body repeats it only when this document has a name of its own.
                'showTitle' => $modelTitle !== null && $modelTitle !== $title,
            ],
            $title,
            // Only the content is used: the response's own Content-Disposition cannot carry a title with accents.
            'document.pdf',
            draftWatermark: $draft,
            header: $header,
            orientation: $orientation,
            footerHtml: $footer,
            margins: $margins,
        );

        return (string) $response->getContent();
    }

    /**
     * @param array<string, mixed>  $definition the revision's definition with the options of its lists filled (FormListOptions)
     * @param array<string, mixed>  $data
     * @param array<string, string> $context
     */
    public function buildForRevision(FormModelRevision $revision, array $definition, array $data, EducationalCentre $centre, string $title, bool $blank = false, array $context = []): string
    {
        $document = $revision->getRevision();

        return $this->build(
            $definition,
            $data,
            $centre,
            $title,
            $revision->getCode(),
            $document->getVersion(),
            $blank,
            $context,
            $revision->getModel()->getDocument()->getName(),
            $document->getRevisedAt(),
            $document->getApprovedAt(),
        );
    }
}
