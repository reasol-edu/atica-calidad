<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Search\Ocr\OcrEngineInterface;
use App\Search\Ocr\OcrException;
use App\Search\Ocr\OcrUnavailableException;

/** An OCR engine that reads no real page: each page of a "PDF" yields the text given for it, and every call is recorded. */
final class FakeOcrEngine implements OcrEngineInterface
{
    public bool $available = true;

    /** Pages of any PDF it is asked about. */
    public int $pages = 3;

    /** @var array<int, string> text per page (1-based); a page without an entry reads "Texto de la página N de prueba." */
    public array $pageText = [];

    /** @var list<int> pages whose recognition fails */
    public array $failingPages = [];

    public string $imageText = 'Texto de una imagen escaneada';

    public bool $brokenPdf = false;

    /** @var list<int> pages recognised, in order */
    public array $recognised = [];

    public int $imagesRecognised = 0;

    public int $pageCountCalls = 0;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function unavailableReason(): ?string
    {
        return $this->available ? null : 'Faltan herramientas: Tesseract.';
    }

    public function languages(): ?string
    {
        return $this->available ? 'spa+eng' : null;
    }

    public function pdfPageCount(string $pdfPath): int
    {
        ++$this->pageCountCalls;
        if (!$this->available) {
            throw new OcrUnavailableException('no instalado');
        }
        if ($this->brokenPdf) {
            throw new OcrException('No se pudo contar las páginas del PDF.');
        }

        return $this->pages;
    }

    public function recognizePdfPage(string $pdfPath, int $page): string
    {
        if (!$this->available) {
            throw new OcrUnavailableException('no instalado');
        }
        if (\in_array($page, $this->failingPages, true)) {
            throw new OcrException('tesseract no terminó');
        }
        $this->recognised[] = $page;

        return $this->pageText[$page] ?? \sprintf('Texto de la página %d de prueba.', $page);
    }

    public function recognizeImage(string $imagePath): string
    {
        if (!$this->available) {
            throw new OcrUnavailableException('no instalado');
        }
        ++$this->imagesRecognised;

        return $this->imageText;
    }
}
