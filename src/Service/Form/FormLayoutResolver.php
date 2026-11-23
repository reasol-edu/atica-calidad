<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Service\AppSettingsInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Turns the layout of a model into what a file needs (DocumentLayout): the header and footer of the model, or the centre's (or the
 * platform's) when the model has none of its own, with the fields filled in.
 */
final class FormLayoutResolver
{
    public const string HEADER_SETTING = 'forms.document_header';
    public const string FOOTER_SETTING = 'forms.document_footer';

    public function __construct(
        private readonly AppSettingsInterface $settings,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.doc_layout')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    /**
     * The header and footer the centre has by default (its own setting, else the platform's, else the built-in tables).
     *
     * @return array{header: string, footer: string}
     */
    public function inherited(EducationalCentre $centre): array
    {
        return [
            'header' => FormLayout::html($this->settings->getForCentre(self::HEADER_SETTING, $centre), $this->sanitizer) ?? FormLayout::defaultHeader(),
            'footer' => FormLayout::html($this->settings->getForCentre(self::FOOTER_SETTING, $centre), $this->sanitizer) ?? FormLayout::defaultFooter(),
        ];
    }

    /**
     * @param array<string, mixed> $definition a model's (or a revision's) definition
     * @param array<string, string> $values    the fields of the header and footer, see values()
     */
    public function resolve(array $definition, EducationalCentre $centre, array $values): DocumentLayout
    {
        $layout    = FormLayout::clean($definition['layout'] ?? null, $this->sanitizer);
        $inherited = $this->inherited($centre);

        return new DocumentLayout(
            FormLayout::fill($layout['header'] ?? $inherited['header'], $values),
            FormLayout::fill($layout['footer'] ?? $inherited['footer'], $values),
            $layout['orientation'],
            $layout['fontScale'],
        );
    }

    /**
     * @param int|string $revision the number of the revision (or a word for a draft)
     *
     * @return array<string, string>
     */
    public function values(EducationalCentre $centre, string $title, ?string $code, int|string $revision, \DateTimeImmutable $date): array
    {
        return [
            'centro'         => $centre->getName(),
            'localidad'      => $centre->getCity(),
            'codigo'         => (string) $code,
            'titulo'         => $title,
            'revision'       => (string) $revision,
            'fecha_revision' => $date->format('d/m/Y'),
        ];
    }
}
