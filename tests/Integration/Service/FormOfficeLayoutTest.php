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

    /** A 2×1 PNG as the centre's logo (and the same as its education authority's). */
    private function giveTheCentreLogos(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAHgAAAA8CAIAAAAiz+n/AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAoklEQVR4nO3QQRHAIADAMMDXvujAv4qpWHksUdDr3OcZfG/dDvgLoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHXjNUAVulkrf3AAAAAElFTkSuQmCC', true);
        $file = new \App\Entity\SettingFile(hash('sha256', $png), $png, 'image/png', strlen($png));
        $this->persist($file);
        foreach (['centre.logo', 'centre.education_authority_logo'] as $key) {
            $def = $this->em->getRepository(\App\Entity\SettingDefinition::class)->findOneBy(['key' => $key])
                ?? (new \App\Entity\SettingDefinition())->setKey($key)->setType(\App\Entity\SettingType::Image)->setDefaultValue('')->setCentreScope(true)->setGlobalScope(true);
            $this->persist($def, (new \App\Entity\CentreSettingValue())->setDefinition($def)->setCentre($this->centre)->setValue('logo.png')->setFile($file));
        }
        self::getContainer()->get(\App\Service\AppSettings::class)->invalidate();
    }

    public function testLogosAreEmbeddedInTheHeaderOfBothFormats(): void
    {
        $this->giveTheCentreLogos();
        $header = '<table><tbody><tr><td>{logo}</td><td>{centro}</td><td>{logo2}</td></tr></tbody></table>';

        $odt = $this->parts($this->file('odt', ['header' => $header]));
        self::assertArrayHasKey('Pictures/logo.png', $odt);
        self::assertArrayHasKey('Pictures/logo2.png', $odt);
        self::assertStringContainsString('<draw:image xlink:href="Pictures/logo.png"', $odt['styles.xml']);
        self::assertStringContainsString('manifest:full-path="Pictures/logo2.png"', $odt['META-INF/manifest.xml']);

        $docx = $this->parts($this->file('docx', ['header' => $header]));
        self::assertArrayHasKey('word/media/logo.png', $docx);
        self::assertStringContainsString('r:embed="rIdImglogo"', $docx['word/header1.xml']);
        self::assertStringContainsString('Target="media/logo2.png"', $docx['word/_rels/header1.xml.rels']);
        self::assertStringContainsString('Extension="png"', $docx['[Content_Types].xml']);
    }

    public function testAHeaderOfTheFirstPageIsAnotherMasterPageInOdtAndAnotherHeaderPartInDocx(): void
    {
        $layout = ['firstHeader' => '<p>Portada de {centro}</p>'];

        $odt = $this->parts($this->file('odt', $layout));
        self::assertStringContainsString('style:name="First_20_Page"', $odt['styles.xml']);
        self::assertStringContainsString('style:next-style-name="Standard"', $odt['styles.xml']);
        self::assertStringContainsString('Portada de IES Ada Lovelace', $odt['styles.xml']);
        self::assertStringContainsString('style:master-page-name="First_20_Page"', $odt['styles.xml']);
        self::assertStringContainsString('text:style-name="FirstPageStart"', $odt['content.xml']);

        $docx = $this->parts($this->file('docx', $layout));
        self::assertStringContainsString('<w:titlePg/>', $docx['word/document.xml']);
        self::assertStringContainsString('w:headerReference w:type="first"', $docx['word/document.xml']);
        self::assertStringContainsString('Portada de IES Ada Lovelace', $docx['word/header2.xml']);
        self::assertArrayHasKey('word/footer2.xml', $docx);

        // Without one of its own the file has a single header.
        self::assertArrayNotHasKey('word/header2.xml', $this->parts($this->file('docx')));
        self::assertStringNotContainsString('First_20_Page', $this->parts($this->file('odt'))['styles.xml']);
    }

    public function testTheMarginsOfTheLayoutReachTheFiles(): void
    {
        $margins = ['top' => 12, 'right' => 20, 'bottom' => 8, 'left' => 25];

        $odt = $this->parts($this->file('odt', ['margins' => $margins]));
        self::assertStringContainsString('fo:margin-top="1.2cm" fo:margin-bottom="0.8cm" fo:margin-left="2.5cm" fo:margin-right="2cm"', $odt['styles.xml']);

        $docx = $this->parts($this->file('docx', ['margins' => $margins]));
        self::assertStringContainsString('w:top="680" w:right="1134" w:bottom="454" w:left="1418"', $docx['word/document.xml']);
    }

    public function testTheApprovalDateIsTheRevisionDateWhenNothingSaysOtherwiseAndEmptyWhilePending(): void
    {
        $header = '<p>R {fecha_revision} A {fecha_aprobacion}</p>';
        $resolver = $this->layouts;
        $dl = $resolver->resolve(['layout' => ['header' => $header]], $this->centre, $resolver->values($this->centre, 'T', 'C', 1, new \DateTimeImmutable('2026-11-20')));
        self::assertStringContainsString('R 20/11/2026 A 20/11/2026', $dl->headerHtml);

        $dl = $resolver->resolve(['layout' => ['header' => $header]], $this->centre, $resolver->values($this->centre, 'T', 'C', 1, new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-11-25')));
        self::assertStringContainsString('R 20/11/2026 A 25/11/2026', $dl->headerHtml);

        $dl = $resolver->resolve(['layout' => ['header' => $header]], $this->centre, $resolver->values($this->centre, 'T', 'C', 1, new \DateTimeImmutable('2026-11-20'), null, true));
        self::assertStringContainsString('R 20/11/2026 A </p>', $dl->headerHtml);
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
        // 10 pt at 150 % is 15 pt: 30 half-points in the default of the document, in a sans-serif font.
        self::assertStringContainsString('<w:sz w:val="30"/>', $docx['word/styles.xml']);
        self::assertStringContainsString('w:ascii="Arial"', $docx['word/styles.xml']);

        $odt = $this->parts($this->file('odt', ['orientation' => 'landscape', 'fontScale' => 50]));
        self::assertStringContainsString('style:print-orientation="landscape"', $odt['styles.xml']);
        self::assertStringContainsString('fo:font-size="5pt"', $odt['styles.xml']);
        self::assertStringContainsString('style:font-family-generic="swiss"', $odt['styles.xml']);
    }

    public function testADocumentWithItsOwnNameShowsItAsTheTitleOfTheBody(): void
    {
        self::assertStringContainsString('Recién llegado - Antón', $this->parts($this->file('odt', [], 'Recién llegado - Antón'))['content.xml']);
        self::assertStringNotContainsString('class="title"', $this->parts($this->file('odt'))['content.xml']);
    }
}
