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

/**
 * Repeating sections (by the rows of a table, by a number field, by hand, nested), the references inside them, conditions and the
 * numbering of copies — the "programación didáctica" case: a list of units whose rows generate one section each.
 */
final class FormRepetitionTest extends TestCase
{
    private FormDefinitionNormalizer $definitions;
    private FormDataNormalizer $data;
    private FormDataValidator $validator;
    private FormResolver $resolver;

    /** @var array<string, string> */
    private array $k = [];

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

    /**
     * 1. Unidades (table: number, title, hours) · 2. Desarrollo → «UD {{copy.number}}. {{row:title}} ({{row:hours}})» one per row, each with a
     * required text, a "Con prácticas" yes/no and a section «Prácticas» that only shows when it is yes · 3. Grupos (a number) → «Grupo
     * {{copy.number}}» with a table of marks.
     *
     * @return array<string, mixed>
     */
    private function definition(bool $numberCopies = true): array
    {
        $key = static fn (): string => FormDefinition::newKey();
        $this->k = [
            'units' => $key(), 'colNumber' => $key(), 'colTitle' => $key(), 'colHours' => $key(),
            'dev' => $key(), 'contents' => $key(), 'practical' => $key(), 'practices' => $key(),
            'groups' => $key(), 'count' => $key(), 'marks' => $key(), 'markCol' => $key(), 'course' => $key(), 'centre' => $key(),
        ];

        return $this->definitions->normalize(['sections' => [
            ['key' => $this->k['units'] . 'x', 'title' => 'Unidades', 'blocks' => [[
                'key' => $this->k['units'], 'type' => 'table', 'label' => 'UD', 'mode' => 'free', 'columns' => [
                    ['key' => $this->k['colNumber'], 'label' => 'Nº', 'fieldType' => 'short'],
                    ['key' => $this->k['colTitle'], 'label' => 'Título', 'fieldType' => 'short', 'required' => true],
                    ['key' => $this->k['colHours'], 'label' => 'Horas', 'fieldType' => 'number', 'unit' => 'h'],
                ],
            ]]],
            ['title' => 'Desarrollo', 'blocks' => [], 'children' => [[
                'key' => $this->k['dev'], 'title' => 'UD {{copy.number}}. {{Título|row:' . $this->k['colTitle'] . '}} ({{row:' . $this->k['colHours'] . '}})',
                'numberCopies' => $numberCopies,
                'repeat' => ['mode' => 'table', 'table' => $this->k['units']],
                'blocks' => [
                    ['key' => $this->k['contents'], 'type' => 'text', 'mode' => 'fill', 'label' => 'Contenidos de {{row:' . $this->k['colTitle'] . '}}', 'required' => true],
                    ['key' => $this->k['practical'], 'type' => 'field', 'fieldType' => 'bool', 'label' => 'Con prácticas'],
                ],
                'children' => [[
                    'title' => 'Prácticas', 'visibleIf' => ['field' => $this->k['practical'], 'op' => 'true'],
                    'blocks' => [['key' => $this->k['practices'], 'type' => 'text', 'mode' => 'fill', 'label' => 'Prácticas', 'required' => true]],
                ]],
            ]]],
            ['title' => 'Grupos', 'blocks' => [['key' => $this->k['count'], 'type' => 'field', 'fieldType' => 'number', 'label' => 'Nº de grupos']], 'children' => [[
                'key' => $this->k['groups'], 'title' => 'Grupo {{copy.number}} de {{copy.total}} en {{ctx:centre}}',
                'repeat' => ['mode' => 'field', 'field' => $this->k['count']],
                'blocks' => [['key' => $this->k['marks'], 'type' => 'table', 'label' => 'Notas', 'mode' => 'free', 'minRows' => 1, 'columns' => [
                    ['key' => $this->k['markCol'], 'label' => 'Nota', 'fieldType' => 'number'],
                ]]],
            ]]],
        ]]);
    }

    /** @return array<string, mixed> */
    private function dataWithUnits(array $definition): array
    {
        $rows = [
            ['_key' => '11111111-1111-4111-8111-111111111111', $this->k['colNumber'] => '1', $this->k['colTitle'] => 'Redes', $this->k['colHours'] => 40],
            ['_key' => '22222222-2222-4222-8222-222222222222', $this->k['colNumber'] => '2', $this->k['colTitle'] => 'Bases de datos', $this->k['colHours'] => 60],
        ];

        return $this->data->normalize($definition, ['tables' => [$this->k['units'] => $rows]]);
    }

    public function testASectionThatRepeatsByTableHasOneCopyPerRowKeyedByTheRow(): void
    {
        $def  = $this->definition();
        $data = $this->dataWithUnits($def);

        $copies = $data['copies'][$this->k['dev']];
        self::assertCount(2, $copies);
        self::assertSame('11111111-1111-4111-8111-111111111111', $copies[0]['_key']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $copies[0]['_source']);
    }

    public function testACopyKeepsItsContentWhenTheRowsAreReorderedAndLosesItWhenItsRowIsDeleted(): void
    {
        $def  = $this->definition();
        $data = $this->dataWithUnits($def);

        $data['copies'][$this->k['dev']][0]['texts'][$this->k['contents']] = ['html' => '<p>Modelo OSI</p>'];
        $data = $this->data->normalize($def, $data);

        // The two rows swap places: the copy of "Redes" is still "Redes", now second.
        $rows = array_reverse($data['tables'][$this->k['units']]);
        $data = $this->data->normalize($def, ['tables' => [$this->k['units'] => $rows]] + $data);
        self::assertSame('<p>Modelo OSI</p>', $data['copies'][$this->k['dev']][1]['texts'][$this->k['contents']]['html']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $data['copies'][$this->k['dev']][0]['_key']);

        // The row of "Redes" is deleted: its copy goes with it.
        $data = $this->data->normalize($def, ['tables' => [$this->k['units'] => [$rows[0]]]] + $data);
        self::assertCount(1, $data['copies'][$this->k['dev']]);
        self::assertSame('22222222-2222-4222-8222-222222222222', $data['copies'][$this->k['dev']][0]['_key']);
    }

    public function testReferencesInTitlesAndLabelsReadTheRowTheCopyComesFrom(): void
    {
        $def  = $this->definition();
        $data = $this->dataWithUnits($def);

        $view = $this->resolver->resolve($def, $data, ['centre' => 'IES Ada']);
        $dev  = $view[1]['children'];

        self::assertCount(2, $dev);
        self::assertSame('UD 1. Redes (40 h)', $dev[0]['title']);
        self::assertSame('UD 2. Bases de datos (60 h)', $dev[1]['title']);
        self::assertSame('Contenidos de Redes', $dev[0]['blocks'][0]['label']);
    }

    public function testNumberingOfCopiesIsChosenByTheModel(): void
    {
        $def      = $this->definition(true);
        $numbered = $this->resolver->resolve($def, $this->dataWithUnits($def));
        self::assertSame('2.1', $numbered[1]['children'][0]['number']);
        self::assertSame('2.2', $numbered[1]['children'][1]['number']);

        $plain = $this->definition(false);
        $view  = $this->resolver->resolve($plain, $this->dataWithUnits($plain));
        self::assertNull($view[1]['children'][0]['number']);
        self::assertSame('3', $view[2]['number'], 'the numbering of what comes after is not disturbed');
    }

    public function testASectionThatRepeatsByANumberFieldFollowsTheNumber(): void
    {
        $def  = $this->definition();
        $data = $this->data->normalize($def, ['fields' => [$this->k['count'] => 3]]);

        self::assertCount(3, $data['copies'][$this->k['groups']]);
        self::assertCount(1, $data['copies'][$this->k['groups']][0]['tables'][$this->k['marks']], 'a new copy starts with the minimum rows of its table');

        $keys = array_column($data['copies'][$this->k['groups']], '_key');
        $data['copies'][$this->k['groups']][1]['tables'][$this->k['marks']][0][$this->k['markCol']] = 7;
        $less = $this->data->normalize($def, ['fields' => [$this->k['count'] => 2]] + $data);
        self::assertSame(array_slice($keys, 0, 2), array_column($less['copies'][$this->k['groups']], '_key'), 'lowering the number drops the last copies');
        self::assertEquals(7, $less['copies'][$this->k['groups']][1]['tables'][$this->k['marks']][0][$this->k['markCol']]);

        $view = $this->resolver->resolve($def, $less, ['centre' => 'IES Ada']);
        self::assertSame('Grupo 2 de 2 en IES Ada', $view[2]['children'][1]['title']);

        $absurd = $this->data->normalize($def, ['fields' => [$this->k['count'] => 5000]]);
        self::assertCount(FormDataNormalizer::MAX_COPIES, $absurd['copies'][$this->k['groups']]);
    }

    public function testManualCopiesStartAtTheirMinimumAndCanBeEmptied(): void
    {
        $def = $this->definitions->normalize(['sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Actividad', 'repeat' => ['mode' => 'manual', 'min' => 2, 'max' => 3],
            'blocks' => [['type' => 'field', 'fieldType' => 'short', 'label' => 'Nombre']],
        ]]]);
        $key = $def['sections'][0]['key'];

        self::assertCount(2, $this->data->initial($def)['copies'][$key]);
        self::assertCount(0, $this->data->normalize($def, ['copies' => [$key => []]])['copies'][$key], 'an emptied list stays empty');
        self::assertCount(3, $this->data->normalize($def, ['copies' => [$key => [[], [], [], [], []]]])['copies'][$key], 'the maximum is kept');
    }

    public function testNestedRepeatingSectionsOpenTheirOwnScopes(): void
    {
        $def = $this->definitions->normalize(['sections' => [[
            'key' => 'a0000000-0000-4000-8000-000000000001', 'title' => 'Grupo', 'repeat' => ['mode' => 'manual', 'min' => 2],
            'blocks' => [], 'children' => [[
                'key' => 'a0000000-0000-4000-8000-000000000002', 'title' => 'Unidad {{copy.number}}', 'repeat' => ['mode' => 'manual', 'min' => 1],
                'blocks' => [['key' => 'a0000000-0000-4000-8000-000000000003', 'type' => 'field', 'fieldType' => 'short', 'label' => 'Tema']],
            ]],
        ]]]);
        $data = $this->data->initial($def);

        self::assertCount(2, $data['copies']['a0000000-0000-4000-8000-000000000001']);
        self::assertCount(1, $data['copies']['a0000000-0000-4000-8000-000000000001'][1]['copies']['a0000000-0000-4000-8000-000000000002']);

        $view = $this->resolver->resolve($def, $data);
        self::assertCount(2, $view, 'one section per outer copy');
        self::assertSame('Unidad 1', $view[1]['children'][0]['title']);
        self::assertSame('2.1', $view[1]['children'][0]['number']);
    }

    public function testAHiddenSectionIsNeitherShownNorValidatedAndKeepsWhatWasTyped(): void
    {
        $def  = $this->definition();
        $data = $this->dataWithUnits($def);
        $dev  = $this->k['dev'];

        $problems = $this->validator->problems($def, $this->data->withoutEmptyRows($def, $data));
        self::assertNotContains('Prácticas', array_map(static fn (array $p): string => $p['params']['%label%'], $problems), 'hidden while "Con prácticas" is not yes');

        $data['copies'][$dev][0]['fields'][$this->k['practical']] = true;
        $data = $this->data->normalize($def, $data);
        $problems = $this->validator->problems($def, $data);
        $labels   = array_map(static fn (array $p): string => $p['params']['%label%'], $problems);
        self::assertContains('UD 1. Redes (40 h) · Prácticas', $labels, 'shown (and required) in the copy that says yes');
        self::assertNotContains('UD 2. Bases de datos (60 h) · Prácticas', $labels);

        $view = $this->resolver->resolve($def, $data);
        self::assertCount(1, $view[1]['children'][0]['children'], 'the conditional section appears in the first copy only');
        self::assertCount(0, $view[1]['children'][1]['children']);

        $data['copies'][$dev][0]['texts'][$this->k['practices']] = ['html' => '<p>Montar una red</p>'];
        $data['copies'][$dev][0]['fields'][$this->k['practical']] = false;
        $data = $this->data->normalize($def, $data);
        self::assertStringContainsString('Montar una red', $data['copies'][$dev][0]['texts'][$this->k['practices']]['html'], 'hidden does not mean deleted');
    }

    public function testValidationLocatesAProblemInsideACopy(): void
    {
        $def  = $this->definition();
        $data = $this->dataWithUnits($def);

        $problems = $this->validator->problems($def, $data);
        $inCopy   = array_values(array_filter($problems, static fn (array $p): bool => str_contains($p['path'], ':')));

        self::assertNotEmpty($inCopy);
        self::assertStringContainsString($this->k['dev'] . ':11111111-1111-4111-8111-111111111111', $inCopy[0]['path']);
        self::assertSame($this->k['contents'], $inCopy[0]['block']);
    }

    public function testEmptyRowsAreDroppedAtDeliveryTogetherWithTheirCopies(): void
    {
        $def  = $this->definition();
        $data = $this->dataWithUnits($def);
        $data['tables'][$this->k['units']][] = ['_key' => '33333333-3333-4333-8333-333333333333'];
        $data = $this->data->normalize($def, $data);
        self::assertCount(3, $data['copies'][$this->k['dev']]);

        $final = $this->data->withoutEmptyRows($def, $data);
        self::assertCount(2, $final['tables'][$this->k['units']]);
        self::assertCount(2, $final['copies'][$this->k['dev']]);
    }

    public function testTheBlankSampleShowsOneCopyOfEveryRepeatingSection(): void
    {
        $def    = $this->definition();
        $sample = $this->data->sample($def);

        self::assertCount(1, $sample['copies'][$this->k['dev']]);
        self::assertCount(1, $sample['copies'][$this->k['groups']]);
        self::assertCount(2, $this->resolver->resolve($def, $sample)[2]['children'] + [null, null], 'does not break the resolver');
    }

    public function testProblemsOfARepeatOrConditionThatCannotWork(): void
    {
        $def = $this->definitions->normalize(['sections' => [
            ['title' => 'A', 'blocks' => [['key' => 'b0000000-0000-4000-8000-000000000001', 'type' => 'field', 'fieldType' => 'short', 'label' => 'Texto']],
                'children' => [
                    ['title' => 'Por campo que no es número', 'repeat' => ['mode' => 'field', 'field' => 'b0000000-0000-4000-8000-000000000001'], 'blocks' => []],
                    ['title' => 'Por tabla que no existe', 'repeat' => ['mode' => 'table', 'table' => 'b0000000-0000-4000-8000-000000000009'], 'blocks' => []],
                    ['title' => 'Condición rota', 'visibleIf' => ['field' => 'b0000000-0000-4000-8000-000000000008', 'op' => 'true'], 'blocks' => []],
                    ['title' => 'Se cuenta a sí misma', 'repeat' => ['mode' => 'table', 'table' => 'b0000000-0000-4000-8000-000000000007'], 'blocks' => [[
                        'key' => 'b0000000-0000-4000-8000-000000000007', 'type' => 'table', 'label' => 'T', 'columns' => [['label' => 'c']],
                    ]]],
                ],
            ],
        ]]);
        $codes = array_column($this->definitions->problems($def), 'code');

        self::assertContains('repeat_field_invalid', $codes);
        self::assertContains('repeat_table_invalid', $codes);
        self::assertContains('condition_field_missing', $codes);
        self::assertContains('repeat_inside_itself', $codes);
    }
}
