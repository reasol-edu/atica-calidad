<?php

declare(strict_types=1);

namespace App\Service;

use Mpdf\Mpdf;
use setasign\Fpdi\FpdiException;

/**
 * Validates that an uploaded PDF is suitable for use as a background template for
 * reports: it must be a readable single-page PDF (SetDocTemplate repeats the
 * template on every generated page, so multiple pages would give a confusing
 * result) whose orientation matches the destination slot.
 */
final class PdfTemplateValidator
{
    /** @param 'P'|'L' $expectedOrientation */
    public function validate(string $content, string $expectedOrientation): ?PdfTemplateValidationError
    {
        $path = tempnam(sys_get_temp_dir(), 'pdfval_');
        if ($path === false) {
            return PdfTemplateValidationError::InvalidPdf;
        }

        try {
            file_put_contents($path, $content);

            $mpdf      = new Mpdf(['tempDir' => sys_get_temp_dir()]);
            $pageCount = $mpdf->setSourceFile($path);

            if ($pageCount !== 1) {
                return PdfTemplateValidationError::MultiPage;
            }

            $tplId = $mpdf->importPage(1);
            $size  = $mpdf->getTemplateSize($tplId);

            if (!is_array($size) || ($size['orientation'] ?? null) !== $expectedOrientation) {
                return PdfTemplateValidationError::WrongOrientation;
            }

            return null;
        } catch (FpdiException) {
            return PdfTemplateValidationError::InvalidPdf;
        } finally {
            @unlink($path);
        }
    }
}
