<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\PdfTemplateValidationError;
use App\Service\PdfTemplateValidator;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PHPUnit\Framework\TestCase;

final class PdfTemplateValidatorTest extends TestCase
{
    private PdfTemplateValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PdfTemplateValidator();
    }

    private function singlePagePdf(string $orientation): string
    {
        $mpdf = new Mpdf(['format' => 'A4', 'orientation' => $orientation, 'tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>Página única</p>');

        $content = $mpdf->Output('', Destination::STRING_RETURN);
        self::assertIsString($content);

        return $content;
    }

    private function twoPagePdf(): string
    {
        $mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>Página uno</p><pagebreak /><p>Página dos</p>');

        $content = $mpdf->Output('', Destination::STRING_RETURN);
        self::assertIsString($content);

        return $content;
    }

    public function testAcceptsASinglePagePortraitPdfMatchingTheExpectedOrientation(): void
    {
        self::assertNull($this->validator->validate($this->singlePagePdf('P'), 'P'));
    }

    public function testAcceptsASinglePageLandscapePdfMatchingTheExpectedOrientation(): void
    {
        self::assertNull($this->validator->validate($this->singlePagePdf('L'), 'L'));
    }

    public function testRejectsAPortraitPdfWhenLandscapeIsExpected(): void
    {
        self::assertSame(
            PdfTemplateValidationError::WrongOrientation,
            $this->validator->validate($this->singlePagePdf('P'), 'L'),
        );
    }

    public function testRejectsAMultiPagePdf(): void
    {
        self::assertSame(
            PdfTemplateValidationError::MultiPage,
            $this->validator->validate($this->twoPagePdf(), 'P'),
        );
    }

    public function testRejectsContentThatIsNotAValidPdf(): void
    {
        self::assertSame(
            PdfTemplateValidationError::InvalidPdf,
            $this->validator->validate('esto no es un pdf', 'P'),
        );
    }
}
