<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The running header of a PDF: one block of HTML (usually a table) drawn at the top of every page, and the top margin that leaves room
 * for it. With `$stretch` the margin grows to the height the header really has (mPDF measures it), for a header that can be tall.
 */
final readonly class PdfHeader
{
    public function __construct(
        public string $html,
        public int $marginTopMm = 22,
        public bool $stretch = false,
    ) {}

    /** The usual header of the reports: a text on the left and another on the right (both already escaped HTML), under a green rule. */
    public static function twoColumns(string $left, string $right, int $marginTopMm = 22): self
    {
        return new self(
            '<table width="100%" style="border-collapse:collapse;border-bottom:2px solid #46603d;padding-bottom:4px;font-family:sourcesanspro,sans-serif;"><tr>'
            . '<td style="text-align:left;vertical-align:bottom;font-size:10pt;color:#2e2822;">' . $left . '</td>'
            . '<td style="text-align:right;vertical-align:bottom;font-size:9pt;color:#46603d;">' . $right . '</td>'
            . '</tr></table>',
            $marginTopMm,
        );
    }
}
