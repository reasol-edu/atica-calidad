<?php

declare(strict_types=1);

namespace App\Entity;

/** Page orientation of a PrintableCalendar's PDF — see PrintableCalendarPdfBuilder for the default font sizes of each. */
enum PrintableCalendarOrientation: string
{
    case Portrait  = 'portrait';
    case Landscape = 'landscape';

    /**
     * mPDF/PdfRenderer's orientation code.
     *
     * @return 'L'|'P'
     */
    public function pdfCode(): string
    {
        return $this === self::Landscape ? 'L' : 'P';
    }
}
