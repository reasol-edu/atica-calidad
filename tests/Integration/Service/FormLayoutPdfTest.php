<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormPdfBuilder;
use App\Tests\Integration\RepositoryTestCase;

/** The PDF of a model with the header, footer, orientation and font scale of its layout. */
final class FormLayoutPdfTest extends RepositoryTestCase
{
    private FormPdfBuilder $builder;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = self::getContainer()->get(FormPdfBuilder::class);
        $this->centre  = (new EducationalCentre())->setCode('12345678')->setName('IES Ada Lovelace')->setCity('Linares');
        $this->persist($this->centre);
    }

    /** @return array<string, mixed> */
    private function definition(array $layout = []): array
    {
        $blocks = [['key' => FormDefinition::newKey(), 'type' => 'text', 'mode' => 'fixed', 'label' => '', 'html' => '<p>Texto fijo del modelo</p>']];

        return ['version' => 1, 'layout' => $layout, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [], 'blocks' => $blocks,
        ]]];
    }

    private function pdf(array $layout = []): string
    {
        $content = $this->builder->build($this->definition($layout), [], $this->centre, 'Memoria de departamento', 'MD850211', 2, true, [], null, new \DateTimeImmutable('2026-11-20'));
        if (\is_string($dir = getenv('LAYOUT_PDF_OUT')) && $dir !== '') {
            file_put_contents($dir . '/' . md5(serialize($layout)) . '.pdf', $content);
        }

        return $content;
    }

    /** @return array{0: float, 1: float} the size of the first page in points */
    private function pageSize(string $pdf): array
    {
        self::assertSame(1, preg_match('#/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]#', $pdf, $m), 'a page size');

        return [(float) $m[1], (float) $m[2]];
    }

    public function testTheSanitizerKeepsTablesAndTheirFormattingAndDropsTheRest(): void
    {
        $sanitizer = self::getContainer()->get('html_sanitizer.sanitizer.app.doc_layout');
        $clean     = $sanitizer->sanitize('<table style="width: 100%"><tbody><tr><td colspan="2" rowspan="1" data-colwidth="120" style="border: 1px solid #555555; background-color: #eef2ea; position: absolute; background: url(x)"><p style="text-align: center"><strong>{centro}</strong><script>alert(1)</script></p></td></tr></tbody></table>');

        self::assertStringContainsString('colspan="2"', $clean);
        self::assertStringContainsString('data-colwidth="120"', $clean);
        self::assertStringContainsString('text-align: center', $clean);
        self::assertStringContainsString('<strong>{centro}</strong>', $clean);
        self::assertStringContainsString('background-color: #eef2ea', $clean);
        self::assertStringNotContainsString('position', $clean);
        self::assertStringNotContainsString('url(', $clean);
        self::assertStringNotContainsString('alert', $clean);
    }

    public function testTheDefaultLayoutGivesAPortraitPdf(): void
    {
        $pdf = $this->pdf();

        self::assertStringStartsWith('%PDF', $pdf);
        [$w, $h] = $this->pageSize($pdf);
        self::assertLessThan($h, $w);
    }

    public function testALandscapeModelGivesALandscapePdf(): void
    {
        [$w, $h] = $this->pageSize($this->pdf(['orientation' => 'landscape']));

        self::assertGreaterThan($h, $w);
    }

    public function testACustomHeaderAndFooterAndFontScaleBuild(): void
    {
        $header = '<table><tbody><tr><td colspan="2" style="border: 1px solid #000000">{centro}</td></tr><tr><td>{codigo}</td><td>Página {pagina} de {paginas}</td></tr></tbody></table>';
        $pdf    = $this->pdf(['header' => $header, 'footer' => '<p style="text-align: center">{titulo} · {fecha_revision}</p>', 'fontScale' => 150]);

        self::assertStringStartsWith('%PDF', $pdf);
    }
}
