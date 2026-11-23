<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Service\Form\FormLayoutResolver;
use App\Service\Form\FormOfficeWriter;
use App\Tests\Integration\RepositoryTestCase;

/** The DOCX and ODT files of a document: header and footer as the page's, merged cells, page fields, orientation and styling. */
final class FormOfficeLayoutTest extends RepositoryTestCase
{
    private FormOfficeWriter $writer;
    private FormLayoutResolver $layouts;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer  = self::getContainer()->get(FormOfficeWriter::class);
        $this->layouts = self::getContainer()->get(FormLayoutResolver::class);
        $this->centre  = (new EducationalCentre())->setCode('12345678')->setName('IES Ada Lovelace')->setCity('Linares');
        $this->persist($this->centre);
    }

    /** @return list<array<string, mixed>> what FormResolver gives */
    private function sections(): array
    {
        return [[
            'number' => '1', 'title' => 'Datos generales', 'level' => 1, 'children' => [], 'blocks' => [
                ['kind' => 'text', 'label' => 'Valoración', 'empty' => false, 'html' => '<p>Un texto con <strong>negrita</strong> y <em>cursiva</em>.</p><ul><li>Uno</li><li>Dos</li></ul>'],
                ['kind' => 'fields', 'items' => [['label' => 'Curso', 'value' => '2026-2027'], ['label' => 'Horas', 'value' => '12 h']]],
                ['kind' => 'table', 'label' => 'Reuniones', 'fixed' => false, 'columns' => ['Fecha', 'Asistentes'], 'rows' => [['cells' => ['01/10', '12']], ['cells' => ['08/10', '10']]], 'totals' => ['', '22']],
            ],
        ]];
    }

    /** @param array<string, mixed> $layout */
    private function file(string $format, array $layout = [], ?string $heading = null): string
    {
        $definition = ['version' => 1, 'sections' => [], 'layout' => $layout];
        $dl         = $this->layouts->resolve($definition, $this->centre, $this->layouts->values($this->centre, 'Memoria de departamento', 'MD850211', 2, new \DateTimeImmutable('2026-11-20')));
        $content    = $this->writer->build($format, 'Memoria de departamento', $this->sections(), $dl, $heading);
        if (\is_string($dir = getenv('LAYOUT_PDF_OUT')) && $dir !== '') {
            file_put_contents($dir . '/office-' . md5(serialize([$layout, $heading])) . '.' . $format, $content);
        }

        return $content;
    }

    /** @return array<string, string> the XML parts of a ZIP, each checked to be well-formed */
    private function parts(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'atica_test_');
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $xml  = (string) $zip->getFromIndex($i);
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $dom = new \DOMDocument();
                self::assertTrue($dom->loadXML($xml), $name . ' is well-formed XML');
            }
            $parts[$name] = $xml;
        }
        $zip->close();
        @unlink($path);

        return $parts;
    }

    public function testTheOdtHasTheHeaderAsTheMasterPageWithItsMergedCellsAndPageFields(): void
    {
        $parts  = $this->parts($this->file('odt'));
        $styles = $parts['styles.xml'];

        self::assertSame('application/vnd.oasis.opendocument.text', $parts['mimetype']);
        self::assertMatchesRegularExpression('#<style:header>.*<table:table .*table:number-columns-spanned="3".*<table:covered-table-cell/>.*</style:header>#s', $styles);
        self::assertStringContainsString('IES Ada Lovelace - Linares', $styles);
        self::assertStringContainsString('<text:page-number', $styles);
        self::assertStringContainsString('<text:page-count', $styles);
        self::assertStringContainsString('Fecha: 20/11/2026', $styles);
        self::assertStringContainsString('style:print-orientation="portrait"', $styles);
        self::assertStringContainsString('Datos generales', $parts['content.xml']);
        // The body: a header row repeated on every page, and the formatting of the rich text.
        self::assertStringContainsString('<table:table-header-rows>', $parts['content.xml']);
        self::assertStringContainsString('fo:font-weight="bold"', $parts['content.xml']);
    }

    public function testTheDocxHasHeaderAndFooterPartsAndMergesCellsBothWays(): void
    {
        $header = '<table><tbody><tr><td rowspan="2" colspan="1">Logo</td><td colspan="2">{centro}</td></tr><tr><td>{codigo}</td><td>Página {pagina} de {paginas}</td></tr></tbody></table>';
        $parts  = $this->parts($this->file('docx', ['header' => $header]));

        self::assertArrayHasKey('word/header1.xml', $parts);
        self::assertArrayHasKey('word/footer1.xml', $parts);
        self::assertStringContainsString('w:headerReference', $parts['word/document.xml']);
        self::assertStringContainsString('<w:gridSpan w:val="2"/>', $parts['word/header1.xml']);
        self::assertStringContainsString('<w:vMerge w:val="restart"/>', $parts['word/header1.xml']);
        self::assertStringContainsString('<w:vMerge/>', $parts['word/header1.xml']);
        self::assertStringContainsString('PAGE', $parts['word/header1.xml']);
        self::assertStringContainsString('NUMPAGES', $parts['word/header1.xml']);
        self::assertStringContainsString('IES Ada Lovelace', $parts['word/header1.xml']);
        self::assertStringContainsString('<w:tblHeader/>', $parts['word/document.xml']);
    }

    public function testLandscapeAndFontScaleReachTheFiles(): void
    {
        $docx = $this->parts($this->file('docx', ['orientation' => 'landscape', 'fontScale' => 150]));
        self::assertStringContainsString('w:orient="landscape"', $docx['word/document.xml']);
        // 10.5 pt at 150 % is 15.75 pt: 32 half-points in the default of the document.
        self::assertStringContainsString('<w:sz w:val="32"/>', $docx['word/styles.xml']);

        $odt = $this->parts($this->file('odt', ['orientation' => 'landscape', 'fontScale' => 50]));
        self::assertStringContainsString('style:print-orientation="landscape"', $odt['styles.xml']);
        self::assertStringContainsString('fo:font-size="5.3pt"', $odt['styles.xml']);
    }

    public function testADocumentWithItsOwnNameShowsItAsTheTitleOfTheBody(): void
    {
        self::assertStringContainsString('Recién llegado - Antón', $this->parts($this->file('odt', [], 'Recién llegado - Antón'))['content.xml']);
        self::assertStringNotContainsString('class="title"', $this->parts($this->file('odt'))['content.xml']);
    }
}
