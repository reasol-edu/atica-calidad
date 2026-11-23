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
        self::assertSame(['header' => null, 'footer' => null, 'orientation' => 'portrait', 'fontScale' => 100], FormLayout::clean([], null));
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

    public function testMergedCellsAreReadIntoAGridAndTheDefaultHeaderIsThreeRows(): void
    {
        $blocks = (new LayoutHtml())->parse(FormLayout::defaultHeader());
        self::assertCount(1, $blocks);
        $table = $blocks[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertSame(3, $table->rows);
        self::assertSame(3, $table->columns());
        self::assertCount(5, $table->cells);
        self::assertSame(3, $table->cells[0]->colspan);
        self::assertTrue($table->cells[0]->border);
        self::assertSame('eef2ea', $table->cells[0]->background);
        // The page fields are runs of their own.
        $kinds = [];
        foreach ($table->cells[4]->paragraphs as $p) {
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
        self::assertStringContainsString('text-align: right', $out);
    }
}
