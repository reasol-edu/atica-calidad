<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;

/**
 * Resolves which background PDF template should be used for a specific report:
 * the specific template for that report type if it exists, otherwise the
 * general template for the orientation that report needs.
 *
 * @phpstan-import-type ReportType from PdfRenderer
 */
final class PdfTemplateResolver
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    /**
     * A report without a template setting of its own always gets the general one for its
     * orientation instead.
     *
     * @param ReportType $reportType
     */
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
