<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormLayout;
use App\Service\Form\Layout\Cell;
use App\Service\Form\Layout\LayoutHtml;
use App\Service\Form\Layout\Paragraph;
use App\Service\Form\Layout\Run;
use App\Service\Form\Layout\Table;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** The layout of a model (header, footer, orientation, font scale) and the grid its tables are read into. */
final class FormLayoutTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer((new HtmlSanitizerConfig())->allowElement('p')->allowElement('table')->allowElement('tbody')->allowElement('tr')->allowElement('td'));
    }

    public function testAnOldDefinitionGetsTheDefaults(): void
    {
        self::assertSame(FormLayout::defaults(), FormLayout::clean(null, $this->sanitizer));
        self::assertSame(['header' => null, 'footer' => null, 'firstHeader' => null, 'orientation' => 'portrait', 'fontScale' => 100, 'margins' => null], FormLayout::clean([], null));
    }

    public function testMarginsAreAllFourOrNoneAndStayBetweenTheLimits(): void
    {
        self::assertSame(['top' => 12, 'right' => 40, 'bottom' => 5, 'left' => 15], FormLayout::margins(['top' => 12, 'right' => 99, 'bottom' => 1, 'left' => '15']));
        self::assertNull(FormLayout::margins(['top' => 12, 'right' => 15, 'bottom' => 10]), 'one is missing: the model has none of its own');
        self::assertNull(FormLayout::margins(['top' => 'x', 'right' => 1, 'bottom' => 1, 'left' => 1]));
        self::assertNull(FormLayout::margins('big'));
    }

    public function testAFirstPageHeaderIsKeptOnlyWhenItHasSomething(): void
    {
        self::assertNull(FormLayout::clean(['firstHeader' => '<p></p>'], $this->sanitizer)['firstHeader']);
        self::assertSame('<p>Portada</p>', FormLayout::clean(['firstHeader' => '<p>Portada</p>'], $this->sanitizer)['firstHeader']);
    }

    public function testTheLogosAreLeftForTheFormatToDrawAsImages(): void
    {
        self::assertSame('<p>{logo} {logo2} IES</p>', FormLayout::fill('<p>{logo} {logo2} {centro}</p>', ['centro' => 'IES', 'logo' => 'x']));
    }

    public function testLogosAndPageFieldsAreRunsOfTheirOwnInTheGrid(): void
    {
        $blocks = (new LayoutHtml())->parse('<table><tbody><tr><td>{logo}</td><td>{logo2} {pagina}</td></tr></tbody></table>');
        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertSame(Run::LOGO, $blocks[0]->cells[0]->paragraphs[0]->runs[0]->kind);
        self::assertSame([Run::LOGO2, Run::TEXT, Run::PAGE], array_map(static fn (Run $r): string => $r->kind, $blocks[0]->cells[1]->paragraphs[0]->runs));
    }

    public function testALogoIsDrawnWithinTheLimitsKeepingItsProportions(): void
    {
        $png   = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAHgAAAA8CAIAAAAiz+n/AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAoklEQVR4nO3QQRHAIADAMMDXvujAv4qpWHksUdDr3OcZfG/dDvgLoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHXjNUAVulkrf3AAAAAElFTkSuQmCC', true);
        $image = \App\Service\Form\Layout\Image::fromBytes((string) $png);
        self::assertNotNull($image);
        [$w, $h] = $image->sizeCm();
        self::assertLessThanOrEqual(\App\Service\Form\Layout\Image::MAX_WIDTH_CM, $w);
        self::assertEqualsWithDelta(2.0, $w / $h, 0.01, 'a 2×1 image stays 2:1');
        self::assertNull(\App\Service\Form\Layout\Image::fromBytes('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'no SVG');

        // In a narrow cell it shrinks to the cell (keeping the proportions) instead of overflowing it.
        [$w, $h] = $image->sizeCm(1.2);
        self::assertEqualsWithDelta(1.2, $w, 0.001);
        self::assertEqualsWithDelta(0.6, $h, 0.001);
        [$w] = $image->sizeCm(10.0);
        self::assertLessThanOrEqual(\App\Service\Form\Layout\Image::MAX_WIDTH_CM, $w, 'a wide cell does not make it bigger than the limits');
    }

    public function testTheOrientationIsOneOfTheTwoAndTheScaleStaysBetween50And150(): void
    {
        self::assertSame('portrait', FormLayout::clean(['orientation' => 'sideways'], null)['orientation']);
        self::assertSame('landscape', FormLayout::clean(['orientation' => 'landscape'], null)['orientation']);
        self::assertSame(150, FormLayout::clean(['fontScale' => 400], null)['fontScale']);
        self::assertSame(50, FormLayout::clean(['fontScale' => 3], null)['fontScale']);
        self::assertSame(85, FormLayout::clean(['fontScale' => '85'], null)['fontScale']);
        self::assertSame(100, FormLayout::clean(['fontScale' => 'big'], null)['fontScale']);
    }

    public function testAHeaderWithNothingVisibleMeansTheCentresAndWithoutASanitizerItIsDropped(): void
    {
        self::assertNull(FormLayout::clean(['header' => '<p></p>'], $this->sanitizer)['header']);
        self::assertNull(FormLayout::clean(['header' => '<p>Texto</p>'], null)['header']);
        self::assertSame('<p>Texto</p>', FormLayout::clean(['header' => '<p>Texto</p>'], $this->sanitizer)['header']);
        // An empty table is still a header the person drew.
        self::assertNotNull(FormLayout::html('<table><tbody><tr><td></td></tr></tbody></table>', $this->sanitizer));
    }

    public function testTokensAreFilledEscapedAndThePageOnesAreLeftForTheFormat(): void
    {
        $html = '<p>{centro} · {titulo} · {pagina}/{paginas} · {otro}</p>';

        self::assertSame('<p>IES &lt;A&gt; · Memoria · {pagina}/{paginas} · {otro}</p>', FormLayout::fill($html, ['centro' => 'IES <A>', 'titulo' => 'Memoria']));
        self::assertSame('<p>IES · Memoria · 3/9 · {otro}</p>', FormLayout::fill($html, ['centro' => 'IES', 'titulo' => 'Memoria'], ['3', '9']));
    }

    public function testTheDefaultHeaderAndFooterUseOnlyKnownTokens(): void
    {
        foreach ([FormLayout::defaultHeader(), FormLayout::defaultFooter()] as $html) {
            preg_match_all('/\{([a-z_]+)\}/', $html, $m);
            self::assertSame([], array_diff($m[1], FormLayout::TOKENS));
        }
    }

    public function testTheDefaultHeaderIsTheOneTheMigrationSeeds(): void
    {
        // (The migration has one file per engine, each with the same class name: the SQLite one is enough.)
        require_once __DIR__ . '/../../../../migrations/sqlite/Version20261123160000.php';
        $seeded = (new \ReflectionClassConstant(\DoctrineMigrations\Version20261123160000::class, 'NEW'))->getValue();

        self::assertSame($seeded, FormLayout::defaultHeader(), 'the setting\'s default and the built-in one are the same');
    }

    public function testMergedCellsAreReadIntoAGridAndTheDefaultHeaderIsThreeRows(): void
    {
        $blocks = (new LayoutHtml())->parse(FormLayout::defaultHeader());
        self::assertCount(1, $blocks);
        $table = $blocks[0];
        self::assertInstanceOf(Table::class, $table);
        // Like the centre's ODT documents: a logo on each side, three rows tall, and the text between them.
        self::assertSame(3, $table->rows);
        self::assertSame(5, $table->columns());
        self::assertCount(7, $table->cells);
        self::assertSame([3, 1, 0], [$table->cells[0]->rowspan, $table->cells[0]->colspan, $table->cells[0]->col]);
        self::assertSame(Run::LOGO, $table->cells[0]->paragraphs[0]->runs[0]->kind);
        self::assertSame(3, $table->cells[1]->colspan);
        self::assertTrue($table->cells[1]->border);
        self::assertSame('eef2ea', $table->cells[1]->background);
        self::assertSame([3, 4], [$table->cells[2]->rowspan, $table->cells[2]->col]);
        self::assertSame(Run::LOGO2, $table->cells[2]->paragraphs[0]->runs[0]->kind);
        self::assertEqualsWithDelta(373 / 1705, $table->widths[0], 0.001, 'the widths of the ODT');
        // The page fields are runs of their own.
        $kinds = [];
        foreach ($table->cells[6]->paragraphs as $p) {
            foreach ($p->runs as $run) {
                $kinds[] = $run->kind;
            }
        }
        self::assertSame([Run::TEXT, Run::PAGE, Run::TEXT, Run::PAGES], $kinds);
    }

    public function testACellMergedDownCoversTheRowsBelowIt(): void
    {
        $table = (new LayoutHtml())->parse('<table><tbody><tr><td rowspan="2">A</td><td>B</td></tr><tr><td>C</td></tr></tbody></table>')[0];
        self::assertInstanceOf(Table::class, $table);
        $at = $table->placement();

        self::assertTrue($at[0][0]['origin']);
        self::assertFalse($at[1][0]['origin']);
        self::assertSame($at[0][0]['cell'], $at[1][0]['cell']);
        self::assertInstanceOf(Cell::class, $at[1][1]['cell']);
        self::assertSame('C', $at[1][1]['cell']->paragraphs[0]->runs[0]->text);
    }

    public function testFormattingAlignmentAndListsAreRead(): void
    {
        $blocks = (new LayoutHtml())->parse('<p style="text-align: right"><strong>Hola</strong> <em style="color: #ff0000">tú</em></p><ul><li>Uno</li></ul>');

        self::assertCount(2, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertSame('right', $blocks[0]->align);
        self::assertTrue($blocks[0]->runs[0]->bold);
        self::assertSame('ff0000', $blocks[0]->runs[2]->color);
        self::assertSame('• ', $blocks[1]->runs[0]->text);
    }

    public function testThePdfHtmlHasPercentageColumnsAndPageFieldsOfThePdfLibrary(): void
    {
        $html = new LayoutHtml();
        $out  = $html->toPdfHtml($html->parse('<table><tbody><tr><td>{pagina} de {paginas}</td><td style="text-align: right">x</td></tr></tbody></table>'), 9);

        self::assertStringContainsString('{PAGENO} de {nbpg}', $out);
        self::assertStringContainsString('<col width="50%">', $out);
        // Every cell says how wide it is (mPDF takes the widths from the cells when there are merged ones): a logo's column keeps its width
        // with or without the logo.
        self::assertStringContainsString('width: 50%', $out);

        $header = $html->toPdfHtml($html->parse(FormLayout::defaultHeader()), 9, []);
        self::assertStringContainsString('width: 21.88%', $header, 'the logo of the centre: 373 of 1705, as in the ODT');
        self::assertStringContainsString('width: 20.41%', $header, 'the logo of the education authority');
        self::assertStringContainsString('width: 57.71%', $header, 'the text: three columns');
        self::assertStringContainsString('text-align: right', $out);
    }
}
