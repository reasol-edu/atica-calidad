<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Entity\FormModelRevision;
use App\Service\PdfHeader;
use App\Service\PdfRenderer;

/**
 * The PDF of a model (blank, as people will see it) or of a filled document: the stored file of every revision. The definition is
 * resolved once (FormResolver) and a Twig template draws it, with the centre's running header and footer.
 */
final class FormPdfBuilder
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly FormResolver $resolver,
    ) {}

    /**
     * @param array<string, mixed>  $definition
     * @param array<string, mixed>  $data
     * @param array<string, string> $context    what `{{ctx:…}}` references read (see FormResolver)
     *
     * @return string the PDF content
     */
    public function build(array $definition, array $data, EducationalCentre $centre, string $title, ?string $code, int $modelVersion, bool $blank = false, array $context = []): string
    {
        $left  = ($code !== null ? '<strong>' . htmlspecialchars($code) . '</strong> · ' : '') . htmlspecialchars($title);
        $right = htmlspecialchars($centre->getName()) . ' · REV: ' . $modelVersion;

        $response = $this->pdf->render(
            'pdf/form_document.html.twig',
            [
                'centre'   => $centre,
                'title'    => $title,
                'code'     => $code,
                'sections' => $this->resolver->resolve($definition, $data, $context),
                'blank'    => $blank,
            ],
            $title,
            // Only the content is used: the response's own Content-Disposition cannot carry a title with accents.
            'document.pdf',
            header: new PdfHeader($left, $right, 22),
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
        return $this->build($definition, $data, $centre, $title, $revision->getCode(), $revision->getRevision()->getVersion(), $blank, $context);
    }
}
