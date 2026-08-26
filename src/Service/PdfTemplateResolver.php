<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;

/**
 * Resuelve qué plantilla PDF de fondo debe usarse para un informe concreto:
 * la plantilla específica de ese tipo de informe si existe, o si no la
 * plantilla general de la orientación que ese informe necesita.
 */
final class PdfTemplateResolver
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    /** @param 'incident'|'sanction'|'group_stats'|'guard_duty' $reportType */
    public function resolve(string $reportType, string $orientation, EducationalCentre $centre): ?ResolvedSettingFile
    {
        $specific = $this->settings->getFileForCentre("reports.{$reportType}_pdf_template", $centre);
        if ($specific !== null) {
            return $specific;
        }

        $generalKey = $orientation === 'L' ? 'reports.pdf_template_landscape' : 'reports.pdf_template_portrait';

        return $this->settings->getFileForCentre($generalKey, $centre);
    }
}
