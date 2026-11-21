<?php

declare(strict_types=1);

namespace App\Search\Ocr;

/**
 * Reads the text of scanned pages with external tools (OCR). Everything about those tools lives behind this interface so the
 * rest of the application never runs a program itself and tests can stand in a fake.
 */
interface OcrEngineInterface
{
    /** Whether every tool needed is installed and the Spanish language data is present. Cheap after the first call. */
    public function isAvailable(): bool;

    /** Why it is not available, in a short sentence for the administrator; null when it is. */
    public function unavailableReason(): ?string;

    /** Languages the recognition will use (e.g. "spa+eng"); null when not available. */
    public function languages(): ?string;

    /**
     * Number of pages of a PDF.
     *
     * @throws OcrException the file is not a readable PDF (damaged, encrypted)
     * @throws OcrUnavailableException the tools are not installed
     */
    public function pdfPageCount(string $pdfPath): int;

    /**
     * Text of one page (1-based) of a PDF.
     *
     * @throws OcrException the page could not be read (the caller skips it)
     * @throws OcrUnavailableException the tools are not installed
     */
    public function recognizePdfPage(string $pdfPath, int $page): string;

    /**
     * Text of an image file (PNG, JPEG, TIFF).
     *
     * @throws OcrException the image could not be read
     * @throws OcrUnavailableException the tools are not installed
     */
    public function recognizeImage(string $imagePath): string;
}
