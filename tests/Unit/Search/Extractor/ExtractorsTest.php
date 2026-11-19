<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search\Extractor;

use App\Search\Extractor\ExtractionException;
use App\Search\Extractor\OpenDocumentExtractor;
use App\Search\Extractor\OpenXmlExtractor;
use App\Search\Extractor\PdfExtractor;
use App\Search\Extractor\PlainTextExtractor;
use App\Search\Extractor\ZipXml;
use App\Search\TextNormalizer;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PHPUnit\Framework\TestCase;

/** The extractors, on small files built here (a ZIP of XML parts, a PDF made with mpdf), never on files from outside. */
final class ExtractorsTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
    }

    private function tmpFile(string $suffix = ''): string
    {
        $file        = tempnam(sys_get_temp_dir(), 'atica-test-') . $suffix;
        $this->tmp[] = $file;

        return $file;
    }

    /** @param array<string, string> $parts */
    private function zip(array $parts): string
    {
        $path = $this->tmpFile('.zip');
        @unlink($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->tmp[] = $path;

        return $path;
    }

    private function read(\App\Search\Extractor\TextExtractorInterface $extractor, string $path): string
    {
        return TextNormalizer::clean($extractor->extract($path, 1_500_000));
    }

    public function testDocxBodyHeaderAndFootnotesAreRead(): void
    {
        $w    = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $path = $this->zip([
            'word/document.xml'  => "<w:document $w><w:body><w:p><w:r><w:t>Protocolo de</w:t></w:r><w:r><w:t xml:space=\"preserve\"> convivencia</w:t></w:r></w:p><w:p><w:r><w:t>Segundo párrafo</w:t></w:r></w:p></w:body></w:document>",
            'word/header1.xml'   => "<w:hdr $w><w:p><w:r><w:t>IES Ada Lovelace</w:t></w:r></w:p></w:hdr>",
            'word/footnotes.xml' => "<w:footnotes $w><w:footnote><w:p><w:r><w:t>Nota al pie</w:t></w:r></w:p></w:footnote></w:footnotes>",
        ]);

        $text = $this->read(new OpenXmlExtractor(), $path);

        self::assertStringContainsString('Protocolo de convivencia', $text);
        self::assertStringContainsString('Segundo párrafo', $text);
        self::assertStringContainsString('IES Ada Lovelace', $text);
        self::assertStringContainsString('Nota al pie', $text);
        // Paragraphs end a word: the last word of one never runs into the first of the next.
        self::assertStringNotContainsString('convivenciaSegundo', $text);
    }

    public function testPptxSlidesAndNotesAreRead(): void
    {
        $a    = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
        $path = $this->zip([
            'ppt/presentation.xml'             => '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
            'ppt/slides/slide1.xml'            => "<p:sld $a><a:p><a:r><a:t>Plan de acogida</a:t></a:r></a:p></p:sld>",
            'ppt/slides/slide2.xml'            => "<p:sld $a><a:p><a:r><a:t>Objetivos del curso</a:t></a:r></a:p></p:sld>",
            'ppt/notesSlides/notesSlide1.xml'  => "<p:notes $a><a:p><a:r><a:t>Recordar la tutoría inicial</a:t></a:r></a:p></p:notes>",
        ]);

        $text = $this->read(new OpenXmlExtractor(), $path);

        self::assertStringContainsString('Plan de acogida', $text);
        self::assertStringContainsString('Objetivos del curso', $text);
        self::assertStringContainsString('Recordar la tutoría inicial', $text);
    }

    public function testXlsxSharedStringsAreRead(): void
    {
        $path = $this->zip([
            'xl/workbook.xml'      => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Indicador de abandono</t></si><si><r><t>Meta </t></r><r><t>trimestral</t></r></si></sst>',
        ]);

        $text = $this->read(new OpenXmlExtractor(), $path);

        self::assertStringContainsString('Indicador de abandono', $text);
        self::assertStringContainsString('Meta trimestral', $text);
    }

    public function testOdtParagraphsAreRead(): void
    {
        $ns   = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"';
        $path = $this->zip([
            'mimetype'    => 'application/vnd.oasis.opendocument.text',
            'content.xml' => "<office:document-content $ns><office:body><office:text><text:h>Criterios de evaluación</text:h><text:p>Se valorará la <text:span>participación</text:span> en clase.</text:p><text:p>Otra<text:s/>línea</text:p></office:text></office:body></office:document-content>",
        ]);

        $text = $this->read(new OpenDocumentExtractor(), $path);

        self::assertStringContainsString('Criterios de evaluación', $text);
        self::assertStringContainsString('Se valorará la participación en clase.', $text);
        self::assertStringContainsString('Otra línea', $text);
    }

    public function testAPackageThatIsNotAnOfficeDocumentFails(): void
    {
        $path = $this->zip(['readme.txt' => 'no es un documento']);

        $this->expectException(ExtractionException::class);
        (new OpenXmlExtractor())->extract($path, 1000);
    }

    public function testAFileThatIsNotAZipFails(): void
    {
        $path = $this->tmpFile('.docx');
        file_put_contents($path, 'esto no es un zip');

        $this->expectException(ExtractionException::class);
        (new OpenXmlExtractor())->extract($path, 1000);
    }

    public function testAPartThatInflatesPastTheCeilingIsRefusedWithoutReadingItAll(): void
    {
        // A "zip bomb" in miniature: a few KB on disk, far more than the ceiling once inflated.
        $path = $this->zip(['word/document.xml' => '<w:document xmlns:w="x">' . str_repeat('a', ZipXml::MAX_PART_BYTES + 1024) . '</w:document>']);
        self::assertLessThan(200_000, filesize($path));

        $this->expectException(ExtractionException::class);
        (new OpenXmlExtractor())->extract($path, 1000);
    }

    public function testExternalEntitiesInTheXmlAreNeverLoaded(): void
    {
        $secret = $this->tmpFile('.txt');
        file_put_contents($secret, 'CONTENIDO-SECRETO');
        $xml  = "<?xml version=\"1.0\"?><!DOCTYPE d [<!ENTITY x SYSTEM \"file://$secret\">]><w:document xmlns:w=\"http://schemas.openxmlformats.org/wordprocessingml/2006/main\"><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:document>";
        $path = $this->zip(['word/document.xml' => $xml]);

        try {
            $text = $this->read(new OpenXmlExtractor(), $path);
        } catch (ExtractionException) {
            $text = '';
        }

        self::assertStringNotContainsString('CONTENIDO-SECRETO', $text);
    }

    public function testPlainTextInUtf8AndInWindows1252(): void
    {
        $utf8 = $this->tmpFile('.txt');
        file_put_contents($utf8, "\xEF\xBB\xBFActa de la reunión del claustro");
        $latin = $this->tmpFile('.csv');
        file_put_contents($latin, mb_convert_encoding('Año;Programación didáctica', 'Windows-1252', 'UTF-8'));

        self::assertSame('Acta de la reunión del claustro', $this->read(new PlainTextExtractor(), $utf8));
        self::assertSame('Año;Programación didáctica', $this->read(new PlainTextExtractor(), $latin));
    }

    public function testAPdfWithTextIsRead(): void
    {
        $path = $this->tmpFile('.pdf');
        $pdf  = new Mpdf(['tempDir' => sys_get_temp_dir() . '/mpdf']);
        $pdf->WriteHTML('<h1>Protocolo de convivencia</h1><p>El alumnado deberá respetar las normas de educación física.</p>');
        $pdf->Output($path, Destination::FILE);

        $text = $this->read(new PdfExtractor(), $path);

        self::assertStringContainsString('Protocolo de convivencia', $text);
        self::assertStringContainsString('educación física', $text);
    }

    public function testAPdfThatIsNotOneFails(): void
    {
        $path = $this->tmpFile('.pdf');
        file_put_contents($path, 'esto no es un PDF');

        $this->expectException(ExtractionException::class);
        (new PdfExtractor())->extract($path, 1000);
    }

    public function testWhichExtractorReadsWhat(): void
    {
        $xml = new OpenXmlExtractor();
        $odf = new OpenDocumentExtractor();
        $pdf = new PdfExtractor();
        $txt = new PlainTextExtractor();

        // By extension, because a .docx is often detected as application/zip.
        self::assertTrue($xml->supports('application/zip', 'Memoria.DOCX'));
        self::assertTrue($odf->supports('application/zip', 'acta.odt'));
        self::assertTrue($pdf->supports('application/octet-stream', 'informe.pdf'));
        self::assertTrue($txt->supports('application/octet-stream', 'notas.md'));
        // And by MIME type when the name says nothing.
        self::assertTrue($pdf->supports('application/pdf', 'sin-extension'));
        self::assertTrue($xml->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'x'));
        // The old binary formats and images are not read.
        foreach ([$xml, $odf, $pdf, $txt] as $extractor) {
            self::assertFalse($extractor->supports('application/msword', 'viejo.doc'));
            self::assertFalse($extractor->supports('image/png', 'foto.png'));
        }
    }
}
