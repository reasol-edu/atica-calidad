<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the running PDF header and the end-of-content footer for a report
 * type from the 'reports.{type}_header_*' / 'reports.{type}_footer'
 * settings, resolving placeholders like {title} or {student_name} against
 * the given values. {city}, {current_date}, {current_time}, {current_day},
 * {current_month_name} and {current_year} are always added automatically.
 */
final class PdfHeaderBuilder
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rich_text')]
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param 'incident'|'sanction'|'group_stats'|'guard_duty' $type
     * @param array<string, string|int>      $placeholders keys without braces, e.g. 'title' => 'Informe…'
     */
    public function build(string $type, EducationalCentre $centre, array $placeholders): PdfHeader
    {
        $replacements = $this->buildReplacements($centre, $placeholders);

        $margin = $this->settings->getForCentre("reports.{$type}_header_margin", $centre);

        return new PdfHeader(
            $this->resolveSide("reports.{$type}_header_left", $centre, $replacements),
            $this->resolveSide("reports.{$type}_header_right", $centre, $replacements),
            is_int($margin) ? $margin : 22,
        );
    }

    /**
     * @param 'incident'|'sanction' $type
     * @param array<string, string|int> $placeholders keys without braces
     */
    public function buildFooter(string $type, EducationalCentre $centre, array $placeholders): string
    {
        $replacements = $this->buildReplacements($centre, $placeholders);

        return $this->resolveSide("reports.{$type}_footer", $centre, $replacements);
    }

    /**
     * @param array<string, string|int> $placeholders
     * @return array<string, string>
     */
    private function buildReplacements(EducationalCentre $centre, array $placeholders): array
    {
        $now = $this->clock->now();

        $placeholders += [
            'city'                => $centre->getCity(),
            'current_date'        => $now->format('d/m/Y'),
            'current_time'        => $now->format('H:i'),
            'current_day'         => $now->format('j'),
            'current_month_name'  => mb_strtolower($this->translator->trans('month.' . $now->format('n'), [], 'calendar')),
            'current_year'        => $now->format('Y'),
        ];

        $replacements = [];
        foreach ($placeholders as $key => $value) {
            $replacements['{' . $key . '}'] = htmlspecialchars((string) $value, ENT_QUOTES);
        }

        return $replacements;
    }

    /** @param array<string, string> $replacements */
    private function resolveSide(string $settingKey, EducationalCentre $centre, array $replacements): string
    {
        $raw = $this->settings->getForCentre($settingKey, $centre);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        // Se sanea antes de sustituir: los tokens {x} sobreviven como texto
        // plano y los valores sustituidos ya llegan escapados, de modo que
        // nunca se inyecta HTML no permitido en el encabezado.
        return strtr($this->sanitizer->sanitize($raw), $replacements);
    }
}
