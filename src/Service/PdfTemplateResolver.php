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
     * orientation instead. "printable_calendar" is the one report type that's actually generated
     * in either orientation (the teacher chooses per calendar), so — unlike every other report,
     * fixed to a single orientation — it gets its own specific setting per orientation, not one
     * shared between both.
     *
     * @param ReportType $reportType
     */
    public function resolve(string $reportType, string $orientation, EducationalCentre $centre): ?ResolvedSettingFile
    {
        $suffix      = $orientation === 'L' ? 'landscape' : 'portrait';
        $specificKey = $reportType === 'printable_calendar'
            ? "reports.{$reportType}_pdf_template_{$suffix}"
            : "reports.{$reportType}_pdf_template";
        $specific    = $this->settings->getFileForCentre($specificKey, $centre);
        if ($specific !== null) {
            return $specific;
        }

        $generalKey = $orientation === 'L' ? 'reports.pdf_template_landscape' : 'reports.pdf_template_portrait';

        return $this->settings->getFileForCentre($generalKey, $centre);
    }
}
