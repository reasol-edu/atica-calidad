<?php

declare(strict_types=1);

namespace App\Service;

use Mpdf\Mpdf;
use setasign\Fpdi\FpdiException;

/**
 * Valida que un PDF subido sea apto para usarse como plantilla de fondo de
 * los informes: debe ser un PDF legible de una sola página (SetDocTemplate
 * repite la plantilla en cada página generada, así que varias páginas darían
 * un resultado confuso) cuya orientación coincida con la ranura de destino.
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
