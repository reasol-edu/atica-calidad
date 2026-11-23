<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Entity\FormModelRevision;
use App\Service\Form\Layout\LayoutHtml;
use App\Service\PdfHeader;
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
    public function build(array $definition, array $data, EducationalCentre $centre, string $title, ?string $code, int $modelVersion, bool $blank = false, array $context = [], ?string $modelTitle = null, ?\DateTimeImmutable $revisedAt = null): string
    {
        $layout = $this->layouts->resolve($definition, $centre, $this->layouts->values($centre, $modelTitle ?? $title, $code, $modelVersion, $revisedAt ?? new \DateTimeImmutable()));

        $header = new PdfHeader($this->html->toPdfHtml($this->html->parse($layout->headerHtml), 9), 22, true);
        $footer = $this->html->toPdfHtml($this->html->parse($layout->footerHtml), 8);

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
            header: $header,
            orientation: $layout->isLandscape() ? 'L' : 'P',
            footerHtml: $footer,
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
        );
    }
}
