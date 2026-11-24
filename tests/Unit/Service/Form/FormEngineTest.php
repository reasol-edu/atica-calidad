<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormCalculator;
use App\Service\Form\FormDataNormalizer;
use App\Service\Form\FormDataValidator;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormEvaluator;
use App\Service\Form\FormResolver;
use App\Service\Form\FormWalker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The pure core of the document models: cleaning a definition, cleaning and checking data, numbering and formatting. */
final class FormEngineTest extends TestCase
{
    private FormDefinitionNormalizer $definitions;
    private FormDataNormalizer $data;
    private FormDataValidator $validator;
    private FormResolver $resolver;

    protected function setUp(): void
    {
        $sanitizer         = new HtmlSanitizer((new HtmlSanitizerConfig())->allowElement('p')->allowElement('strong'));
        $this->definitions = new FormDefinitionNormalizer($sanitizer);
        $this->data        = new FormDataNormalizer($sanitizer, new FormCalculator(new FormWalker(), new FormEvaluator()));
        $this->resolver    = new FormResolver(new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id === 'view.yes' ? 'Sí' : 'No';
            }

            public function getLocale(): string
            {
                return 'es';
            }
        }, new FormWalker());
        $this->validator   = new FormDataValidator(new FormWalker(), $this->resolver, new FormEvaluator());
    }

    /** @return array<string, mixed> a normalized definition with: a title field, an hours number, a choice, a free table and a fixed one */
    private function definition(): array
    {
        return $this->definitions->normalize(['sections' => [
            [
                'title'    => 'Datos',
                'blocks'   => [
                    ['type' => 'field', 'fieldType' => 'short', 'label' => 'Título', 'required' => true],
                    ['type' => 'field', 'fieldType' => 'number', 'label' => 'Horas', 'min' => 1, 'max' => 100, 'unit' => 'h'],
                    ['type' => 'field', 'fieldType' => 'choice', 'label' => 'Grado', 'options' => [['label' => 'D'], ['label' => 'E']]],
                    ['type' => 'field', 'fieldType' => 'bool', 'label' => 'Con FFEOE'],
                    ['type' => 'field', 'fieldType' => 'date', 'label' => 'Fecha'],
                ],
                'children' => [
                    [
                        'title'  => 'Unidades',
                        'blocks' => [[
                            'type' => 'table', 'label' => 'UD', 'mode' => 'free', 'minRows' => 1,
                            'columns' => [
                                ['label' => 'Título', 'fieldType' => 'short', 'required' => true],
                                ['label' => 'Horas', 'fieldType' => 'number'],
                            ],
                        ]],
                    ],
                    ['title' => 'Sin número', 'numbered' => false, 'blocks' => [], 'children' => [['title' => 'Hija', 'blocks' => []]]],
                ],
            ],
            ['title' => 'Evaluación', 'blocks' => [[
                'type' => 'table', 'label' => 'Criterios', 'mode' => 'fixed', 'rowHeader' => 'Criterio',
                'columns' => [['label' => 'Cumple', 'fieldType' => 'bool']],
                'rows'    => [['label' => 'Uno'], ['label' => 'Dos']],
            ], [
                'type' => 'text', 'mode' => 'starter', 'label' => 'Metodología', 'html' => '<p>Base</p><script>x</script>',
            ], [
                'type' => 'text', 'mode' => 'fill', 'label' => 'Causas', 'required' => true, 'html' => '<p>ignored</p>',
            ]]],
        ]]);
    }

    public function testNormalizingADefinitionGivesEveryElementAUniqueKeyAndDropsWhatIsUnknown(): void
    {
        $def = $this->definitions->normalize(['sections' => [
            ['key' => 'dup', 'title' => '<b>A</b>', 'blocks' => [['type' => 'weird'], ['type' => 'field', 'fieldType' => 'nope', 'label' => 'x']], 'children' => []],
            ['key' => 'dup', 'title' => 'B', 'blocks' => []],
        ]]);

        $keys = FormDefinition::allKeys($def);
        self::assertSame($keys, array_values(array_unique($keys)), 'no key is repeated');
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $key);
        }
        self::assertSame('A', $def['sections'][0]['title'], 'tags are stripped from titles');
        self::assertCount(1, $def['sections'][0]['blocks'], 'an unknown block type is dropped');
        self::assertSame('short', $def['sections'][0]['blocks'][0]['fieldType'], 'an unknown field type falls back to short');
    }

    public function testKeysSurviveANewSave(): void
    {
        $def   = $this->definition();
        $again = $this->definitions->normalize($def);

        self::assertSame(FormDefinition::allKeys($def), FormDefinition::allKeys($again));
    }

    public function testTextsAreSanitizedInTheDefinition(): void
    {
        $def  = $this->definition();
        $html = $def['sections'][1]['blocks'][1]['html'];

        self::assertStringNotContainsString('script', $html);
        self::assertStringContainsString('Base', $html);
        self::assertSame('', $def['sections'][1]['blocks'][2]['html'], 'a text to fill in carries no starting content');
    }

    public function testProblemsOfAModelThatCannotWork(): void
    {
        $def = $this->definitions->normalize(['sections' => [
            ['title' => '', 'blocks' => [
                ['type' => 'field', 'fieldType' => 'choice', 'label' => 'Elige'],
                ['type' => 'table', 'label' => 'T', 'mode' => 'fixed', 'columns' => []],
            ]],
        ]]);
        $codes = array_column($this->definitions->problems($def), 'code');

        self::assertContains('section_without_title', $codes);
        self::assertContains('choice_without_options', $codes);
        self::assertContains('table_without_columns', $codes);
        self::assertContains('fixed_table_without_rows', $codes);
        self::assertSame([], $this->definitions->problems($this->definition()));
        self::assertSame(['empty'], array_column($this->definitions->problems($this->definitions->normalize([])), 'code'));
    }

    public function testInitialDataHasTheStartingTextsTheFixedRowsAndTheMinimumRows(): void
    {
        $def  = $this->definition();
        $data = $this->data->initial($def);

        $freeKey  = $def['sections'][0]['children'][0]['blocks'][0]['key'];
        $fixedKey = $def['sections'][1]['blocks'][0]['key'];
        $starter  = $def['sections'][1]['blocks'][1]['key'];

        self::assertCount(1, $data['tables'][$freeKey], 'one empty row for the minimum');
        self::assertCount(2, $data['tables'][$fixedKey], 'a fixed table owns its rows');
        self::assertSame($def['sections'][1]['blocks'][0]['rows'][0]['key'], $data['tables'][$fixedKey][0]['_key']);
        self::assertFalse($data['texts'][$starter]['modified']);
        self::assertStringContainsString('Base', $data['texts'][$starter]['html']);
    }

    public function testDataIsCoercedToTheTypeOfEachField(): void
    {
        $def    = $this->definition();
        $blocks = $def['sections'][0]['blocks'];
        [$title, $hours, $grade, $ffeoe, $date] = array_column($blocks, 'key');
        $optionD = $blocks[2]['options'][0]['key'];

        $data = $this->data->normalize($def, ['fields' => [
            $title => '  Unidad <b>1</b> ', $hours => '2,5', $grade => $optionD, $ffeoe => true, $date => '2026-02-30',
            'unknown' => 'x',
        ]]);

        self::assertSame('Unidad 1', $data['fields'][$title]);
        self::assertSame(2.5, $data['fields'][$hours]);
        self::assertSame($optionD, $data['fields'][$grade]);
        self::assertTrue($data['fields'][$ffeoe]);
        self::assertArrayNotHasKey($date, $data['fields'], 'an impossible date is dropped');
        self::assertArrayNotHasKey('unknown', $data['fields']);

        $data = $this->data->normalize($def, ['fields' => [$grade => 'not-an-option', $hours => 'abc', $ffeoe => 'yes']]);
        self::assertSame([], $data['fields']);
    }

    public function testAFixedTableKeepsOnlyItsOwnRowsAndAFreeOneKeepsKeys(): void
    {
        $def      = $this->definition();
        $fixed    = $def['sections'][1]['blocks'][0];
        $free     = $def['sections'][0]['children'][0]['blocks'][0];
        $cumple   = $fixed['columns'][0]['key'];
        $freeCol  = $free['columns'][0]['key'];
        $rowKey   = '11111111-1111-4111-8111-111111111111';

        $data = $this->data->normalize($def, ['tables' => [
            $fixed['key'] => [['_key' => $fixed['rows'][1]['key'], $cumple => true], ['_key' => 'invented', $cumple => true]],
            $free['key']  => [['_key' => $rowKey, $freeCol => 'Redes'], ['_key' => $rowKey, $freeCol => 'Otra']],
        ]]);

        self::assertCount(2, $data['tables'][$fixed['key']]);
        self::assertArrayNotHasKey($cumple, $data['tables'][$fixed['key']][0]);
        self::assertTrue($data['tables'][$fixed['key']][1][$cumple]);
        self::assertSame($rowKey, $data['tables'][$free['key']][0]['_key']);
        self::assertNotSame($rowKey, $data['tables'][$free['key']][1]['_key'], 'a repeated row key is replaced');
    }

    public function testStarterTextKnowsWhenItWasAdapted(): void
    {
        $def     = $this->definition();
        $starter = $def['sections'][1]['blocks'][1]['key'];

        $same    = $this->data->normalize($def, ['texts' => [$starter => ['html' => $def['sections'][1]['blocks'][1]['html']]]]);
        $changed = $this->data->normalize($def, ['texts' => [$starter => ['html' => '<p>Mía</p>', 'modified' => false]]]);

        self::assertFalse($same['texts'][$starter]['modified']);
        self::assertTrue($changed['texts'][$starter]['modified'], 'the server decides, not the browser');
    }

    public function testValidationNamesWhatIsMissingBeforeDelivering(): void
    {
        $def  = $this->definition();
        $data = $this->data->initial($def);

        $problems = $this->validator->problems($def, $this->data->withoutEmptyRows($def, $data));
        $codes    = array_column($problems, 'code');

        self::assertContains('required', $codes, 'the title and the text to fill in are required');
        self::assertContains('rows_min', $codes, 'the empty row was dropped, so the minimum is not met');

        $freeKey = $def['sections'][0]['children'][0]['blocks'][0]['key'];
        $cols    = $def['sections'][0]['children'][0]['blocks'][0]['columns'];
        $title   = $def['sections'][0]['blocks'][0]['key'];
        $hours   = $def['sections'][0]['blocks'][1]['key'];
        $causes  = $def['sections'][1]['blocks'][2]['key'];

        $ok = $this->data->normalize($def, [
            'fields' => [$title => 'X', $hours => 5],
            'texts'  => [$causes => ['html' => '<p>porque</p>']],
            'tables' => [$freeKey => [[$cols[0]['key'] => 'Redes']]],
        ]);
        self::assertSame([], $this->validator->problems($def, $this->data->withoutEmptyRows($def, $ok)));

        $tooMuch = $this->data->normalize($def, ['fields' => [$title => 'X', $hours => 500]] + ['texts' => $ok['texts'], 'tables' => $ok['tables']]);
        self::assertSame(['number_max'], array_column($this->validator->problems($def, $this->data->withoutEmptyRows($def, $tooMuch)), 'code'));
    }

    public function testANumberCanBeWholeOrHaveAtMostSomeDecimals(): void
    {
        $def = $this->definitions->normalize(['sections' => [['title' => 'Datos', 'blocks' => [
            ['type' => 'field', 'fieldType' => 'number', 'label' => 'Alumnos', 'maxDecimals' => 0],
            ['type' => 'field', 'fieldType' => 'number', 'label' => 'Nota', 'maxDecimals' => '2'],
            ['type' => 'field', 'fieldType' => 'number', 'label' => 'Libre'],
            ['type' => 'field', 'fieldType' => 'number', 'label' => 'Demasiados', 'maxDecimals' => 9],
            ['type' => 'field', 'fieldType' => 'short', 'label' => 'Texto', 'maxDecimals' => 0],
            ['type' => 'table', 'label' => 'Grupos', 'mode' => 'free', 'columns' => [['label' => 'Matriculados', 'fieldType' => 'number', 'maxDecimals' => 0]]],
        ]]]]);
        $blocks = $def['sections'][0]['blocks'];
        self::assertSame([0, 2, null, 6, null], array_map(static fn (array $b): ?int => $b['maxDecimals'] ?? null, array_slice($blocks, 0, 5)),
            'a whole number, two decimals, any (as ever), capped at six; only numbers have it');
        self::assertSame(0, $blocks[5]['columns'][0]['maxDecimals']);

        [$whole, $mark, $free] = [$blocks[0]['key'], $blocks[1]['key'], $blocks[2]['key']];
        $table  = $blocks[5]['key'];
        $column = $blocks[5]['columns'][0]['key'];
        $codes  = fn (array $fields, array $rows = []): array => array_column($this->validator->problems($def, $this->data->withoutEmptyRows($def,
            $this->data->normalize($def, ['fields' => $fields, 'tables' => [$table => $rows]]))), 'code');

        self::assertSame([], $codes([$whole => 25, $mark => 7.25, $free => 3.14159], [[$column => 30]]));
        self::assertSame([], $codes([$whole => 25.0, $mark => 7.5]), '25.0 is a whole number; 7.5 has one decimal');
        self::assertSame(['number_integer', 'number_decimals'], $codes([$whole => 25.5, $mark => 7.125]));
        self::assertSame(['number_integer'], $codes([], [[$column => 30.5]]), 'in a table cell too');
        self::assertSame(1, FormDataValidator::decimalsOf(0.1));
        self::assertSame(0, FormDataValidator::decimalsOf(-3.0));
        self::assertSame(2, FormDataValidator::decimalsOf(1.15));
    }

    public function testTheResolverNumbersSectionsHierarchicallyAndFormatsValues(): void
    {
        $def    = $this->definition();
        $blocks = $def['sections'][0]['blocks'];
        $data   = $this->data->normalize($def, ['fields' => [
            $blocks[0]['key'] => 'Mi título', $blocks[1]['key'] => 2.5, $blocks[2]['key'] => $blocks[2]['options'][1]['key'], $blocks[3]['key'] => true, $blocks[4]['key'] => '2026-11-22',
        ]]);

        $view = $this->resolver->resolve($def, $data);

        self::assertSame('1', $view[0]['number']);
        self::assertSame('1.1', $view[0]['children'][0]['number']);
        self::assertNull($view[0]['children'][1]['number'], 'a section that is not numbered has none…');
        self::assertNull($view[0]['children'][1]['children'][0]['number'], '…nor does its subtree');
        self::assertSame('2', $view[1]['number']);

        $items = $view[0]['blocks'][0]['items'];
        self::assertSame('Mi título', $items[0]['value']);
        self::assertSame('2,5 h', $items[1]['value']);
        self::assertSame('E', $items[2]['value']);
        self::assertSame('Sí', $items[3]['value']);
        self::assertSame('22/11/2026', $items[4]['value']);
        self::assertCount(1, $view[0]['blocks'], 'consecutive fields are one card');
    }
}
