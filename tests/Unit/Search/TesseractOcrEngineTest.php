<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\Ocr\OcrUnavailableException;
use App\Search\Ocr\TesseractOcrEngine;
use PHPUnit\Framework\TestCase;

/** The real OCR tools are optional: what the engine says without them always runs; reading a page only where they are installed. */
final class TesseractOcrEngineTest extends TestCase
{
    public function testWithoutToolsItIsUnavailableAndSaysWhy(): void
    {
        $engine = new TesseractOcrEngine('/no/existe/tesseract', '/no/existe/pdftoppm', '/no/existe/pdfinfo');

        self::assertFalse($engine->isAvailable());
        self::assertNull($engine->languages());
        self::assertStringContainsString('Tesseract', (string) $engine->unavailableReason());
        self::assertStringContainsString('pdftoppm', (string) $engine->unavailableReason());
    }

    public function testReadingWithoutToolsWaitsInsteadOfFailingTheFile(): void
    {
        $engine = new TesseractOcrEngine('/no/existe/tesseract', '/no/existe/pdftoppm', '/no/existe/pdfinfo');

        $this->expectException(OcrUnavailableException::class);
        $engine->recognizeImage('/tmp/nada.png');
    }

    public function testItReadsAnImageWhenTheToolsAreInstalled(): void
    {
        $engine = new TesseractOcrEngine();
        if (!$engine->isAvailable()) {
            self::markTestSkipped('Tesseract, poppler-utils o el idioma español no están instalados: el OCR es opcional.');
        }
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagettftext')) {
            self::markTestSkipped('Se necesita GD con FreeType para dibujar la imagen de prueba.');
        }

        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (!is_file($font)) {
            self::markTestSkipped('No hay una fuente TrueType conocida para dibujar la imagen de prueba.');
        }

        $image = imagecreatetruecolor(1200, 200);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagettftext($image, 48, 0, 40, 120, (int) imagecolorallocate($image, 0, 0, 0), $font, 'Acta del claustro');
        $path = sys_get_temp_dir() . '/atica-ocr-test-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);

        try {
            self::assertStringContainsString('claustro', strtolower($engine->recognizeImage($path)));
        } finally {
            @unlink($path);
        }
    }
}
