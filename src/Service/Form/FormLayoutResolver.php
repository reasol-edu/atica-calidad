<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Service\AppSettingsInterface;
use App\Service\Form\Layout\Image;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Turns the layout of a model into what a file needs (DocumentLayout): the header, footer and margins of the model, or the centre's (or
 * the platform's) when the model has none of its own, with the fields filled in and the centre's logos.
 */
final class FormLayoutResolver
{
    public const string HEADER_SETTING = 'forms.document_header';
    public const string FOOTER_SETTING = 'forms.document_footer';

    /** The settings of the margins, by side. */
    public const array MARGIN_SETTINGS = ['top' => 'forms.margin_top', 'bottom' => 'forms.margin_bottom', 'left' => 'forms.margin_left', 'right' => 'forms.margin_right'];

    /** The settings that hold the logos, by the token that draws them. */
    public const array LOGO_SETTINGS = ['logo' => 'centre.logo', 'logo2' => 'centre.education_authority_logo'];

    public function __construct(
        private readonly AppSettingsInterface $settings,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.doc_layout')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    /**
     * The header, footer and margins the centre has by default (its own setting, else the platform's, else the built-in ones).
     *
     * @return array{header: string, footer: string, margins: array{top: int, right: int, bottom: int, left: int}}
     */
    public function inherited(EducationalCentre $centre): array
    {
        return [
            'header'  => FormLayout::html($this->settings->getForCentre(self::HEADER_SETTING, $centre), $this->sanitizer) ?? FormLayout::defaultHeader(),
            'footer'  => FormLayout::html($this->settings->getForCentre(self::FOOTER_SETTING, $centre), $this->sanitizer) ?? FormLayout::defaultFooter(),
            'margins' => [
                'top'    => $this->margin('top', $centre),
                'right'  => $this->margin('right', $centre),
                'bottom' => $this->margin('bottom', $centre),
                'left'   => $this->margin('left', $centre),
            ],
        ];
    }

    /**
     * @param array<string, mixed>  $definition a model's (or a revision's) definition
     * @param array<string, string> $values     the fields of the header and footer, see values()
     */
    public function resolve(array $definition, EducationalCentre $centre, array $values): DocumentLayout
    {
        $layout    = FormLayout::clean($definition['layout'] ?? null, $this->sanitizer);
        $inherited = $this->inherited($centre);
        $header    = FormLayout::fill($layout['header'] ?? $inherited['header'], $values);

        return new DocumentLayout(
            $header,
            FormLayout::fill($layout['footer'] ?? $inherited['footer'], $values),
            $layout['firstHeader'] !== null ? FormLayout::fill($layout['firstHeader'], $values) : '',
            $layout['orientation'],
            $layout['fontScale'],
            $layout['margins'] ?? $inherited['margins'],
            $this->logos($centre),
        );
    }

    /**
     * @param int|string $revision the number of the revision (or a word for a draft)
     *
     * @return array<string, string>
     */
    public function values(EducationalCentre $centre, string $title, ?string $code, int|string $revision, \DateTimeImmutable $revisedAt, ?\DateTimeImmutable $approvedAt = null, bool $pendingApproval = false): array
    {
        return [
            'centro'           => $centre->getName(),
            'localidad'        => $centre->getCity(),
            'codigo'           => (string) $code,
            'titulo'           => $title,
            'revision'         => (string) $revision,
            'fecha_revision'   => $revisedAt->format('d/m/Y'),
            // A revision that needed no review is approved when it is made: the same date. One still pending has no date yet.
            'fecha_aprobacion' => $pendingApproval ? '' : ($approvedAt ?? $revisedAt)->format('d/m/Y'),
        ];
    }

    /** @return array<string, Image> */
    public function logos(EducationalCentre $centre): array
    {
        $images = [];
        foreach (self::LOGO_SETTINGS as $token => $key) {
            $file  = $this->settings->getFileForCentre($key, $centre);
            $image = $file !== null ? Image::fromBytes($file->file->getContent()) : null;
            if ($image !== null) {
                $images[$token] = $image;
            }
        }

        return $images;
    }

    private function margin(string $side, EducationalCentre $centre): int
    {
        $value = $this->settings->getForCentre(self::MARGIN_SETTINGS[$side], $centre);

        // The setting always has a value; if its definition is missing, the usual margins of the documents.
        $fallback = ($side === 'left' || $side === 'right') ? 15 : 10;

        return max(FormLayout::MARGIN_MIN, min(FormLayout::MARGIN_MAX, \is_int($value) ? $value : $fallback));
    }
}
