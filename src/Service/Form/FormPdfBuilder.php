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
    /** The most bytes of attached files put inside one PDF (beyond, the rest are only listed: the ZIP «con anexos» has them all). */
    public const int EMBED_MAX_BYTES = 40 * 1024 * 1024;

    /** A bigger picture is listed, not drawn (the PDF would weigh what the picture does, and more). */
    private const int IMAGE_MAX_BYTES = 5 * 1024 * 1024;

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
     * @param array<string, array{name: string, mime: string, content: string}> $files the files of the fields «Adjuntos», by id (the
     *                                                                                 document is built without them when not given: names only)
     *
     * @return string the PDF content
     */
    public function build(array $definition, array $data, EducationalCentre $centre, string $title, ?string $code, int $modelVersion, bool $blank = false, array $context = [], ?string $modelTitle = null, ?\DateTimeImmutable $revisedAt = null, ?\DateTimeImmutable $approvedAt = null, bool $pendingApproval = false, bool $draft = false, array $files = []): string
    {
        $layout = $this->layouts->resolve($definition, $centre, $this->layouts->values($centre, $modelTitle ?? $title, $code, $modelVersion, $revisedAt ?? new \DateTimeImmutable(), $approvedAt, $pendingApproval));

        // The header, the footer and the first page's header are as tall as the tables drawn in them: they are measured, and the body
        // starts below the header and stops above the footer, with the margins of the layout from the edge of the paper.
        $m           = $layout->margins;
        $orientation = $layout->isLandscape() ? 'L' : 'P';
        // The width of the text: the logos are fitted to the cells of the tables, which share it.
        $contentCm   = (($layout->isLandscape() ? 297 : 210) - $m['left'] - $m['right']) / 10;
        $headerHtml  = $this->html->toPdfHtml($this->html->parse($layout->headerHtml), 9, $layout->images, $contentCm);
        $footer      = $this->html->toPdfHtml($this->html->parse($layout->footerHtml), 8, $layout->images, $contentCm);
        $headerMm    = $this->pdf->heightMm($headerHtml, $orientation, $m['left'], $m['right']);
        $footerMm    = $this->pdf->heightMm($footer, $orientation, $m['left'], $m['right']);
        $firstHtml   = null;
        $firstExtra  = 0.0;
        if ($layout->hasFirstHeader()) {
            $firstHtml  = $this->html->toPdfHtml($this->html->parse($layout->firstHeaderHtml), 9, $layout->images, $contentCm);
            $firstExtra = max(0.0, $this->pdf->heightMm($firstHtml, $orientation, $m['left'], $m['right']) - $headerMm);
        }
        $header  = new PdfHeader($headerHtml, $m['top'] + $headerMm + 4, false, $firstHtml, $firstExtra);
        $margins = new PdfMargins($m['left'], $m['right'], $m['bottom'] + $footerMm + 3, $m['top'], $m['bottom']);

        $sections = $this->resolver->resolve($definition, $data, $context);
        $annexes  = FormAttachments::fromResolved($sections);
        $extra    = $this->annexes($annexes, $files);

        $response = $this->pdf->render(
            'pdf/form_document.html.twig',
            [
                'centre'      => $centre,
                'title'       => $title,
                'code'        => $code,
                'sections'    => $sections,
                'blank'       => $blank,
                'fontScale'   => $layout->fontScale,
                // The header already says the title of the model: the body repeats it only when this document has a name of its own.
                'showTitle'   => $modelTitle !== null && $modelTitle !== $title,
                'annexes'     => $annexes,
                'annexImages' => $extra['images'],
                'annexPages'  => $extra['pageCounts'],
                'embedded'    => $extra['embedded'] !== [],
            ],
            $title,
            // Only the content is used: the response's own Content-Disposition cannot carry a title with accents.
            'document.pdf',
            draftWatermark: $draft,
            header: $header,
            orientation: $orientation,
            footerHtml: $footer,
            margins: $margins,
            embeddedFiles: $extra['embedded'],
            appendedPdfs: $extra['pages'],
        );

        return (string) $response->getContent();
    }

    /**
     * @param array<string, mixed>  $definition the revision's definition with the options of its lists filled (FormListOptions)
     * @param array<string, mixed>  $data
     * @param array<string, string> $context
     * @param array<string, array{name: string, mime: string, content: string}> $files the files of the fields «Adjuntos», by id
     */
    public function buildForRevision(FormModelRevision $revision, array $definition, array $data, EducationalCentre $centre, string $title, bool $blank = false, array $context = [], array $files = []): string
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
            files: $files,
        );
    }

    /**
     * What the PDF does with the files of the fields «Adjuntos»: a picture is drawn where its field is; every file goes inside the PDF
     * as an embedded file (the panel of attachments of the PDF readers), up to EMBED_MAX_BYTES in all; and the pages of an attached
     * PDF go at the end when its field asks for it and the library can read it (some compressed PDFs it cannot: then it is only listed).
     *
     * @param list<array<string, mixed>>                                  $annexes FormAttachments::fromResolved()
     * @param array<string, array{name: string, mime: string, content: string}> $files
     *
     * @return array{images: array<string, string>, embedded: list<array{name: string, mime: string, content: string, description: string}>, pages: list<array{title: string, content: string}>, pageCounts: array<string, int>}
     */
    private function annexes(array $annexes, array $files): array
    {
        $images = $pageCounts = [];
        $embedded = $pages = [];
        $budget   = self::EMBED_MAX_BYTES;
        foreach ($annexes as $annex) {
            $id   = FormDefinition::str($annex['id'] ?? '');
            $file = $files[$id] ?? null;
            if ($file === null) {
                continue;
            }
            $label = sprintf('Anexo %d: %s', FormDefinition::int($annex['n'] ?? 0), $file['name']);
            if (($annex['image'] ?? false) === true && strlen($file['content']) <= self::IMAGE_MAX_BYTES) {
                $images[$id] = 'data:' . $file['mime'] . ';base64,' . base64_encode($file['content']);
            }
            if (strlen($file['content']) <= $budget) {
                $budget    -= strlen($file['content']);
                $embedded[] = ['name' => sprintf('%02d-%s', FormDefinition::int($annex['n'] ?? 0), $file['name']), 'mime' => $file['mime'], 'content' => $file['content'], 'description' => $label];
            }
            if (($annex['printPages'] ?? false) === true) {
                $count = $this->pdf->pageCount($file['content']);
                if ($count > 0) {
                    $pageCounts[$id] = $count;
                    $pages[]         = ['title' => $label, 'content' => $file['content']];
                }
            }
        }

        return ['images' => $images, 'embedded' => $embedded, 'pages' => $pages, 'pageCounts' => $pageCounts];
    }
}
