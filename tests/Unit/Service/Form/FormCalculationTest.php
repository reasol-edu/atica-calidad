<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormCalculator;
use App\Service\Form\FormDataNormalizer;
use App\Service\Form\FormDataValidator;
use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormEvaluator;
use App\Service\Form\FormResolver;
use App\Service\Form\FormWalker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Calculated fields and columns, totals, consistency rules, relation columns and double-entry tables. */
final class FormCalculationTest extends TestCase
{
    private FormDefinitionNormalizer $definitions;
    private FormDataNormalizer $data;
    private FormDataValidator $validator;
    private FormResolver $resolver;

    /** @var array<string, string> */
    private array $k = [];

    protected function setUp(): void
    {
        $sanitizer         = new HtmlSanitizer((new HtmlSanitizerConfig())->allowElement('p'));
        $walker            = new FormWalker();
        $evaluator         = new FormEvaluator();
        $this->definitions = new FormDefinitionNormalizer($sanitizer);
        $this->data        = new FormDataNormalizer($sanitizer, new FormCalculator($walker, $evaluator));
        $this->resolver    = new FormResolver(new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id === 'view.yes' ? 'Sí' : 'No';
            }

            public function getLocale(): string
            {
                return 'es';
            }
        }, $walker);
        $this->validator = new FormDataValidator($walker, $this->resolver, $evaluator);
    }

    /**
     * Units (title, hours, price, calculated cost = hours × price, total of hours and cost) · a calculated total of hours and a rounded
     * average · a RA table and a double-entry UD × RA weight table · a relation column "RA of the activity" · two rules.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        $k = [];
        foreach (['units', 'cTitle', 'cHours', 'cPrice', 'cCost', 'total', 'avg', 'ras', 'raName', 'weights', 'acts', 'actName', 'actRa', 'name', 'limit'] as $n) {
            $k[$n] = $this->key($n);
        }
        $this->k = $k;

        return $this->definitions->normalize(['sections' => [[
            'title' => 'Todo', 'blocks' => [
                ['key' => $k['limit'], 'type' => 'field', 'fieldType' => 'number', 'label' => 'Horas del módulo'],
                ['key' => $k['units'], 'type' => 'table', 'label' => 'Unidades', 'mode' => 'free', 'columns' => [
                    ['key' => $k['cTitle'], 'label' => 'Título', 'fieldType' => 'short'],
                    ['key' => $k['cHours'], 'label' => 'Horas', 'fieldType' => 'number', 'total' => 'sum'],
                    ['key' => $k['cPrice'], 'label' => 'Precio', 'fieldType' => 'number'],
                    ['key' => $k['cCost'], 'label' => 'Coste', 'fieldType' => 'calculated', 'formula' => '[r:' . $k['cHours'] . '] * [r:' . $k['cPrice'] . ']', 'decimals' => 2, 'unit' => '€', 'total' => 'sum'],
                ]],
                ['key' => $k['total'], 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'Horas totales', 'formula' => 'sum([t:' . $k['units'] . '.' . $k['cHours'] . '])', 'decimals' => 0],
                ['key' => $k['avg'], 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'Media', 'formula' => 'round(avg([t:' . $k['units'] . '.' . $k['cHours'] . ']); 1)', 'decimals' => 1],
                ['key' => $k['ras'], 'type' => 'table', 'label' => 'RA', 'mode' => 'free', 'columns' => [['key' => $k['raName'], 'label' => 'RA', 'fieldType' => 'short']]],
                ['key' => $k['weights'], 'type' => 'table', 'label' => 'Ponderación', 'mode' => 'matrix', 'rowTable' => $k['units'], 'rowLabel' => $k['cTitle'], 'colTable' => $k['ras'], 'colLabel' => $k['raName'], 'cell' => ['fieldType' => 'number', 'unit' => '%']],
                ['key' => $k['acts'], 'type' => 'table', 'label' => 'Actividades', 'mode' => 'free', 'columns' => [
                    ['key' => $k['actName'], 'label' => 'Actividad', 'fieldType' => 'short'],
                    ['key' => $k['actRa'], 'label' => 'RA', 'fieldType' => 'relation', 'relTable' => $k['ras'], 'relLabel' => $k['raName'], 'multiple' => true],
                ]],
            ],
        ]], 'rules' => [
            ['expr' => '[f:' . $k['total'] . '] = [f:' . $k['limit'] . ']', 'message' => 'Las horas de las unidades no suman las del módulo', 'severity' => 'block'],
            ['expr' => 'min([m:' . $k['weights'] . '.cols]) = 100 && max([m:' . $k['weights'] . '.cols]) = 100', 'message' => 'Cada RA debe sumar 100 %', 'severity' => 'warning'],
        ]]);
    }

    private function key(string $name): string
    {
        return sprintf('%08x-0000-4000-8000-%012x', crc32($name), crc32('x' . $name));
    }

    /** @return array<string, mixed> */
    private function filled(array $def): array
    {
        $k = $this->k;

        return $this->data->normalize($def, [
            'fields' => [$k['limit'] => 100, $k['total'] => 999, $k['avg'] => 1],
            'tables' => [
                $k['units'] => [
                    ['_key' => 'a0000000-0000-4000-8000-000000000001', $k['cTitle'] => 'Redes', $k['cHours'] => 40, $k['cPrice'] => 2.5, $k['cCost'] => 'trampa'],
                    ['_key' => 'a0000000-0000-4000-8000-000000000002', $k['cTitle'] => 'BD', $k['cHours'] => 60, $k['cPrice'] => 1],
                ],
                $k['ras'] => [
                    ['_key' => 'b0000000-0000-4000-8000-000000000001', $k['raName'] => 'RA1'],
                    ['_key' => 'b0000000-0000-4000-8000-000000000002', $k['raName'] => 'RA2'],
                ],
                $k['weights'] => [
                    ['_key' => 'a0000000-0000-4000-8000-000000000001', 'b0000000-0000-4000-8000-000000000001' => 60, 'b0000000-0000-4000-8000-000000000002' => 40],
                    ['_key' => 'a0000000-0000-4000-8000-000000000002', 'b0000000-0000-4000-8000-000000000001' => 40, 'b0000000-0000-4000-8000-000000000002' => 'x'],
                ],
                $k['acts'] => [
                    ['_key' => 'c0000000-0000-4000-8000-000000000001', $k['actName'] => 'Práctica', $k['actRa'] => ['b0000000-0000-4000-8000-000000000002', 'b0000000-0000-4000-8000-0000000000ff', 'b0000000-0000-4000-8000-000000000001']],
                ],
            ],
        ]);
    }

    public function testCalculatedFieldsAndColumnsAreComputedAndCannotBeTyped(): void
    {
        $def  = $this->definition();
        $data = $this->filled($def);
        $k    = $this->k;

        self::assertSame(100, $data['fields'][$k['total']], 'what the browser sent for a calculated field is ignored');
        self::assertSame(50.0, (float) $data['fields'][$k['avg']]);
        self::assertEquals(100.0, $data['tables'][$k['units']][0][$k['cCost']], '40 × 2.5');
        self::assertEquals(60.0, $data['tables'][$k['units']][1][$k['cCost']], '60 × 1');
    }

    public function testACalculatedValueWithAMissingTermHasNoValue(): void
    {
        $def  = $this->definition();
        $data = $this->data->normalize($def, ['tables' => [$this->k['units'] => [['_key' => 'a0000000-0000-4000-8000-000000000001', $this->k['cHours'] => 40]]]]);

        self::assertArrayNotHasKey($this->k['cCost'], $data['tables'][$this->k['units']][0]);
    }

    public function testRulesBlockOrWarnAsTheModelSays(): void
    {
        $def  = $this->definition();
        $data = $this->filled($def);

        $problems = $this->validator->problems($def, $data);
        $rules    = array_values(array_filter($problems, static fn (array $p): bool => $p['code'] === 'rule'));

        self::assertCount(1, $rules, 'hours add up to 100, so only the weights rule is broken: RA2 sums 40');
        self::assertSame('warning', $rules[0]['severity']);
        self::assertSame('Cada RA debe sumar 100 %', $rules[0]['params']['%message%']);
        self::assertSame([], $this->validator->blocking($rules), 'a warning does not stop the delivery');

        $data['fields'][$this->k['limit']] = 90;
        $rules = array_values(array_filter($this->validator->problems($def, $this->data->normalize($def, $data)), static fn (array $p): bool => $p['code'] === 'rule'));
        self::assertCount(2, $rules);
        self::assertCount(1, $this->validator->blocking($rules));
    }

    public function testARelationKeepsOnlyTheRowsThatExistAndShowsTheirLabels(): void
    {
        $def  = $this->definition();
        $data = $this->filled($def);
        $k    = $this->k;

        self::assertSame(
            ['b0000000-0000-4000-8000-000000000002', 'b0000000-0000-4000-8000-000000000001'],
            $data['tables'][$k['acts']][0][$k['actRa']],
            'the one that does not exist is dropped',
        );

        $view   = $this->resolver->resolve($def, $data);
        $tables = array_values(array_filter($view[0]['blocks'], static fn (array $b): bool => $b['kind'] === 'table'));
        $acts   = array_values(array_filter($tables, static fn (array $t): bool => $t['label'] === 'Actividades'))[0];
        self::assertSame('RA1, RA2', $acts['rows'][0]['cells'][1], 'in the order of the table they point at');

        // The RA is deleted: the relation forgets it.
        $data['tables'][$k['ras']] = [$data['tables'][$k['ras']][0]];
        $data = $this->data->normalize($def, $data);
        self::assertSame(['b0000000-0000-4000-8000-000000000001'], $data['tables'][$k['acts']][0][$k['actRa']]);
    }

    public function testADoubleEntryTableHasARowPerUnitAndACellPerRa(): void
    {
        $def  = $this->definition();
        $data = $this->filled($def);
        $k    = $this->k;

        $matrix = $data['tables'][$k['weights']];
        self::assertCount(2, $matrix);
        self::assertSame(60, $matrix[0]['b0000000-0000-4000-8000-000000000001']);
        self::assertArrayNotHasKey('b0000000-0000-4000-8000-000000000002', $matrix[1], 'a cell that is not a number is dropped');

        $data['tables'][$k['units']][] = ['_key' => 'a0000000-0000-4000-8000-000000000003', $k['cTitle'] => 'Nueva'];
        $data = $this->data->normalize($def, $data);
        self::assertCount(3, $data['tables'][$k['weights']], 'a new unit is a new row');

        $view   = $this->resolver->resolve($def, $data);
        $tables = array_values(array_filter($view[0]['blocks'], static fn (array $b): bool => $b['kind'] === 'table'));
        $matrixView = array_values(array_filter($tables, static fn (array $t): bool => $t['label'] === 'Ponderación'))[0];
        self::assertSame(['RA1', 'RA2'], $matrixView['columns']);
        self::assertSame('Redes', $matrixView['rows'][0]['label']);
        self::assertSame(['60 %', '40 %'], $matrixView['rows'][0]['cells']);
    }

    public function testTotalsAreShownAtTheFootOfAColumn(): void
    {
        $def  = $this->definition();
        $view = $this->resolver->resolve($def, $this->filled($def));

        $units = array_values(array_filter($view[0]['blocks'], static fn (array $b): bool => $b['kind'] === 'table' && $b['label'] === 'Unidades'))[0];
        self::assertSame(['', '100', '', '160,00 €'], $units['totals']);
    }

    public function testCalculatedValuesAreFormattedWithTheirDecimals(): void
    {
        $def    = $this->definition();
        $view   = $this->resolver->resolve($def, $this->filled($def));
        $fields = array_merge(...array_column(array_filter($view[0]['blocks'], static fn (array $b): bool => $b['kind'] === 'fields'), 'items'));

        $byLabel = array_column($fields, 'value', 'label');
        self::assertSame('100', $byLabel['Horas totales']);
        self::assertSame('50,0', $byLabel['Media']);
    }

    public function testAggregatesReadAcrossTheCopiesOfARepeatingSection(): void
    {
        $count = $this->key('count');
        $group = $this->key('group');
        $hours = $this->key('hours');
        $sum   = $this->key('sumhours');
        $def   = $this->definitions->normalize(['sections' => [[
            'title' => 'Datos', 'blocks' => [
                ['key' => $count, 'type' => 'field', 'fieldType' => 'number', 'label' => 'Grupos'],
                ['key' => $sum, 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'Horas de todos', 'formula' => 'sum([c:' . $group . '.f:' . $hours . '])', 'decimals' => 0],
            ],
            'children' => [['key' => $group, 'title' => 'Grupo {{copy.number}}', 'repeat' => ['mode' => 'field', 'field' => $count], 'blocks' => [
                ['key' => $hours, 'type' => 'field', 'fieldType' => 'number', 'label' => 'Horas'],
            ]]],
        ]]]);

        $data = $this->data->normalize($def, ['fields' => [$count => 2]]);
        $data['copies'][$group][0]['fields'][$hours] = 10;
        $data['copies'][$group][1]['fields'][$hours] = 32;
        $data = $this->data->normalize($def, $data);

        self::assertSame(42, $data['fields'][$sum]);
    }

    public function testACalculatedValueInsideACopyReadsTheRowItComesFrom(): void
    {
        $units = $this->key('u');
        $title = $this->key('ut');
        $hours = $this->key('uh');
        $dev   = $this->key('dev');
        $twice = $this->key('twice');
        $def   = $this->definitions->normalize(['sections' => [[
            'title' => 'S', 'blocks' => [['key' => $units, 'type' => 'table', 'label' => 'U', 'mode' => 'free', 'columns' => [
                ['key' => $title, 'label' => 'T', 'fieldType' => 'short'], ['key' => $hours, 'label' => 'H', 'fieldType' => 'number'],
            ]]],
            'children' => [['key' => $dev, 'title' => 'UD', 'repeat' => ['mode' => 'table', 'table' => $units], 'blocks' => [
                ['key' => $twice, 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'Doble', 'formula' => '[o:' . $hours . '] * 2', 'decimals' => 0],
            ]]],
        ]]]);

        $data = $this->data->normalize($def, ['tables' => [$units => [['_key' => 'a0000000-0000-4000-8000-000000000001', $hours => 21], ['_key' => 'a0000000-0000-4000-8000-000000000002', $hours => 5]]]]);

        self::assertSame(42, $data['copies'][$dev][0]['fields'][$twice]);
        self::assertSame(10, $data['copies'][$dev][1]['fields'][$twice]);
    }

    public function testANamedFootTotalIsAFieldOfTheDocumentThatIsOnlyShownAtTheFootOfItsColumn(): void
    {
        $table  = 'c0000000-0000-4000-8000-000000000001';
        $column = 'c0000000-0000-4000-8000-000000000002';
        $total  = 'c0000000-0000-4000-8000-000000000003';
        $other  = 'c0000000-0000-4000-8000-000000000004';
        $raw    = ['sections' => [['title' => 'Datos', 'blocks' => [
            ['key' => $table, 'type' => 'table', 'label' => 'Reuniones', 'mode' => 'free', 'columns' => [
                ['key' => $column, 'label' => 'Asistentes', 'fieldType' => 'number', 'total' => 'sum', 'totalName' => 'Total de asistentes', 'totalKey' => $total],
            ]],
            ['key' => $other, 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'Doble', 'formula' => '[f:' . $total . '] * 2', 'decimals' => 0],
        ]]]];
        $def = $this->definitions->normalize($raw);
        self::assertSame([], $this->definitions->problems($def));

        $blocks = $def['sections'][0]['blocks'];
        self::assertCount(3, $blocks, 'the table, the field of its named total and the other field');
        self::assertSame($total, $blocks[1]['key'], 'the key given to the total is kept');
        self::assertTrue($blocks[1]['foot']);
        self::assertSame('sum([t:' . $table . '.' . $column . '])', $blocks[1]['formula']);

        // Saving what has already been normalized changes nothing: the field is made again, not duplicated.
        self::assertEquals($def, $this->definitions->normalize($def));

        $rows = [['_key' => 'd0000000-0000-4000-8000-000000000001', $column => 10], ['_key' => 'd0000000-0000-4000-8000-000000000002', $column => 4]];
        $data = $this->data->normalize($def, ['tables' => [$table => $rows]]);
        self::assertEquals(14, $data['fields'][$total]);
        self::assertEquals(28, $data['fields'][$other], 'a formula can read it');

        $view   = $this->resolver->resolve($def, $data);
        $kinds  = array_map(static fn (array $b): string => $b['kind'], $view[0]['blocks']);
        $fields = array_values(array_filter($view[0]['blocks'], static fn (array $b): bool => $b['kind'] === 'fields'));
        self::assertSame(['table', 'fields'], $kinds);
        self::assertCount(1, $fields[0]['items'], 'only the other field is shown as a field');
        self::assertSame('14', $view[0]['blocks'][0]['totals'][0]);

        // Without a total or without a name there is no field.
        $raw['sections'][0]['blocks'][0]['columns'][0]['total'] = '';
        $def = $this->definitions->normalize($raw);
        self::assertCount(2, $def['sections'][0]['blocks']);
        self::assertSame('', $def['sections'][0]['blocks'][0]['columns'][0]['totalName']);
    }

    public function testALongTextFieldIsRichTextAndAPlainOneIsReadAsParagraphs(): void
    {
        $field = 'c0000000-0000-4000-8000-000000000001';
        $table = 'c0000000-0000-4000-8000-000000000002';
        $col   = 'c0000000-0000-4000-8000-000000000003';
        $def   = $this->definitions->normalize(['sections' => [['title' => 'Datos', 'blocks' => [
            ['key' => $field, 'type' => 'field', 'fieldType' => 'long', 'label' => 'Valoración'],
            ['key' => $table, 'type' => 'table', 'label' => 'T', 'mode' => 'free', 'columns' => [['key' => $col, 'label' => 'Nota', 'fieldType' => 'long']]],
        ]]]]);
        $row = ['_key' => 'd0000000-0000-4000-8000-000000000001', $col => "una\nlínea"];

        $data = $this->data->normalize($def, ['fields' => [$field => '<p>Hola</p><script>x()</script><p>mundo</p>'], 'tables' => [$table => [$row]]]);
        self::assertSame('<p>Hola</p><p>mundo</p>', $data['fields'][$field], 'the HTML is cleaned');
        self::assertSame("una\nlínea", $data['tables'][$table][0][$col], 'a cell of a table stays plain text');

        $data = $this->data->normalize($def, ['fields' => [$field => "Primero\n\nOtro párrafo & más"]]);
        self::assertSame('<p>Primero</p><p>Otro párrafo &amp; más</p>', $data['fields'][$field], 'plain text becomes paragraphs');
        self::assertSame('<p>Primero<br>segunda línea</p><p>Otro</p>', \App\Service\Form\FormRichText::html("Primero\nsegunda línea\n\nOtro"), 'a line break inside a paragraph is kept');
        self::assertSame("Primero\nsegunda", \App\Service\Form\FormRichText::plain('<p>Primero<br>segunda</p>'));

        $data = $this->data->normalize($def, ['fields' => [$field => '<p><br></p>']]);
        self::assertArrayNotHasKey($field, $data['fields'], 'an empty editor is no value');

        $data = $this->data->normalize($def, ['fields' => [$field => '<p>Hola</p><p>mundo</p>']]);
        $view = $this->resolver->resolve($def, $data);
        $item = $view[0]['blocks'][0]['items'][0];
        self::assertSame("Hola\nmundo", $item['value'], 'the value reads as plain text');
        self::assertSame('<p>Hola</p><p>mundo</p>', $item['html'], 'the document shows the HTML');

        // What was stored as plain text before the field was rich still shows, as paragraphs.
        $view = $this->resolver->resolve($def, ['fields' => [$field => "Antiguo\n\nTexto"]]);
        self::assertSame('<p>Antiguo</p><p>Texto</p>', $view[0]['blocks'][0]['items'][0]['html']);
    }

    public function testACustomFootTotalIsAFormulaShownAtTheFootEvenWithoutAName(): void
    {
        $table  = 'c0000000-0000-4000-8000-000000000001';
        $column = 'c0000000-0000-4000-8000-000000000002';
        $total  = 'c0000000-0000-4000-8000-000000000003';
        $def    = $this->definitions->normalize(['sections' => [['title' => 'Datos', 'blocks' => [
            ['key' => $table, 'type' => 'table', 'label' => 'Reuniones', 'mode' => 'free', 'columns' => [
                ['key' => $column, 'label' => 'Asistentes', 'fieldType' => 'number', 'total' => 'custom', 'totalFormula' => 'max([t:' . $table . '.' . $column . ']) - min([t:' . $table . '.' . $column . '])'],
            ]],
        ]]]]);
        self::assertSame([], $this->definitions->problems($def));
        self::assertCount(2, $def['sections'][0]['blocks'], 'a custom total is always a field');
        self::assertSame('Asistentes', $def['sections'][0]['blocks'][1]['label'], 'named after its column when it has no name');

        $rows = [['_key' => 'd0000000-0000-4000-8000-000000000001', $column => 10], ['_key' => 'd0000000-0000-4000-8000-000000000002', $column => 4]];
        $data = $this->data->normalize($def, ['tables' => [$table => $rows]]);
        $view = $this->resolver->resolve($def, $data);
        self::assertSame('6', $view[0]['blocks'][0]['totals'][0]);

        // Without a formula there is nothing to show.
        $def = $this->definitions->normalize(['sections' => [['title' => 'Datos', 'blocks' => [
            ['key' => $table, 'type' => 'table', 'label' => 'R', 'mode' => 'free', 'columns' => [['key' => $column, 'label' => 'A', 'fieldType' => 'number', 'total' => 'custom']]],
        ]]]]);
        self::assertCount(1, $def['sections'][0]['blocks']);
    }

    /** The formulas an operation over one column of one table takes: count, sum, avg, min and max. */
    public function testTheSummaryOfATableColumnCountsSumsAveragesAndFindsTheExtremes(): void
    {
        $table  = 'c0000000-0000-4000-8000-000000000001';
        $column = 'c0000000-0000-4000-8000-000000000002';
        $fields = [];
        foreach (['count' => 3, 'sum' => 4, 'avg' => 5, 'min' => 6, 'max' => 7] as $fn => $n) {
            $fields[$fn] = 'c0000000-0000-4000-8000-00000000000' . $n;
        }
        $blocks = [['key' => $table, 'type' => 'table', 'label' => 'Reuniones', 'mode' => 'free', 'columns' => [['key' => $column, 'label' => 'Asistentes', 'fieldType' => 'number']]]];
        foreach ($fields as $fn => $key) {
            $blocks[] = ['key' => $key, 'type' => 'field', 'fieldType' => 'calculated', 'label' => $fn, 'formula' => $fn . '([t:' . $table . '.' . $column . '])', 'decimals' => 2];
        }
        $def = $this->definitions->normalize(['sections' => [['title' => 'Datos', 'blocks' => $blocks]]]);
        self::assertSame([], $this->definitions->problems($def));

        $row  = static fn (int $n): string => 'd0000000-0000-4000-8000-00000000000' . $n;
        $rows = [['_key' => $row(1), $column => 10], ['_key' => $row(2), $column => 4], ['_key' => $row(3)], ['_key' => $row(4), $column => 7]];
        $data = $this->data->normalize($def, ['tables' => [$table => $rows]]);

        self::assertEquals(3, $data['fields'][$fields['count']], 'counts the values filled in, not the rows');
        self::assertEquals(21, $data['fields'][$fields['sum']]);
        self::assertEquals(7, $data['fields'][$fields['avg']]);
        self::assertEquals(4, $data['fields'][$fields['min']]);
        self::assertEquals(10, $data['fields'][$fields['max']]);
    }

    public function testProblemsOfCalculationsThatCannotWork(): void
    {
        $a = $this->key('a');
        $b = $this->key('b');
        $t = $this->key('t');
        $def = $this->definitions->normalize(['sections' => [[
            'title' => 'S', 'blocks' => [
                ['key' => $a, 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'A', 'formula' => '[f:' . $b . '] + 1'],
                ['key' => $b, 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'B', 'formula' => '[f:' . $a . '] + 1'],
                ['key' => $this->key('c'), 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'C', 'formula' => '1 +'],
                ['key' => $this->key('d'), 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'D', 'formula' => ''],
                ['key' => $this->key('e'), 'type' => 'field', 'fieldType' => 'calculated', 'label' => 'E', 'formula' => '[f:' . $this->key('nada') . ']'],
                ['key' => $t, 'type' => 'table', 'label' => 'T', 'mode' => 'matrix', 'rowTable' => $t, 'rowLabel' => '', 'colTable' => '', 'colLabel' => '', 'cell' => []],
                ['key' => $this->key('r'), 'type' => 'table', 'label' => 'R', 'mode' => 'free', 'columns' => [['label' => 'x', 'fieldType' => 'relation', 'relTable' => $this->key('missing'), 'relLabel' => '']]],
            ],
        ]], 'rules' => [['expr' => 'foo(1)', 'message' => '', 'severity' => 'block']]]);
        $codes = array_column($this->definitions->problems($def), 'code');

        foreach (['formula_cycle', 'formula_invalid', 'formula_missing', 'formula_ref_missing', 'matrix_invalid', 'relation_invalid', 'rule_without_message'] as $code) {
            self::assertContains($code, $codes);
        }
    }
}
