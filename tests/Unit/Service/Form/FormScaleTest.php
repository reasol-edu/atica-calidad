<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormCalculator;
use App\Service\Form\FormDataNormalizer;
use App\Service\Form\FormDataValidator;
use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormEvaluator;
use App\Service\Form\FormResolver;
use App\Service\Form\FormValueIndexer;
use App\Service\Form\FormWalker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A rating scale («Escala de valoración»): a whole number from 1 to N, with optional words for both ends. */
final class FormScaleTest extends TestCase
{
    private FormDefinitionNormalizer $definitions;
    private FormDataNormalizer $data;
    private FormDataValidator $validator;
    private FormResolver $resolver;

    protected function setUp(): void
    {
        $sanitizer         = new HtmlSanitizer((new HtmlSanitizerConfig())->allowElement('p'));
        $this->definitions = new FormDefinitionNormalizer($sanitizer);
        $this->data        = new FormDataNormalizer($sanitizer, new FormCalculator(new FormWalker(), new FormEvaluator()));
        $this->resolver    = new FormResolver(new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'es';
            }
        }, new FormWalker());
        $this->validator = new FormDataValidator(new FormWalker(), $this->resolver, new FormEvaluator());
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return $this->definitions->normalize(['sections' => [[
            'title'  => 'Encuesta',
            'blocks' => [
                ['type' => 'field', 'fieldType' => 'scale', 'label' => 'Satisfacción', 'required' => true, 'points' => 5, 'lowLabel' => 'Nada', 'highLabel' => 'Mucho', 'min' => 3],
                ['type' => 'table', 'label' => 'Preguntas', 'mode' => 'fixed', 'rowHeader' => 'Pregunta', 'rows' => [['label' => 'Uno'], ['label' => 'Dos']], 'columns' => [
                    ['label' => 'Valoración', 'fieldType' => 'scale', 'points' => 4, 'total' => 'avg'],
                    ['label' => 'Media', 'fieldType' => 'calculated', 'formula' => 'x'],
                ]],
            ],
        ]]]);
    }

    public function testTheDefinitionKeepsTheScaleBetweenItsLimitsAndOnlyForAScale(): void
    {
        $def   = $this->definition();
        $field = $def['sections'][0]['blocks'][0];
        self::assertSame([5, 'Nada', 'Mucho', null], [$field['points'], $field['lowLabel'], $field['highLabel'], $field['min']], 'no number limits on a scale');
        self::assertSame('avg', $def['sections'][0]['blocks'][1]['columns'][0]['total'], 'a scale column can show its average at the foot');

        $clamp = $this->definitions->normalize(['sections' => [['title' => 'x', 'blocks' => [
            ['type' => 'field', 'fieldType' => 'scale', 'points' => 50],
            ['type' => 'field', 'fieldType' => 'scale', 'points' => 1],
            ['type' => 'field', 'fieldType' => 'scale'],
            ['type' => 'field', 'fieldType' => 'number', 'points' => 7, 'lowLabel' => 'x'],
        ]]]]);
        self::assertSame([10, 3, 5, 0], array_column($clamp['sections'][0]['blocks'], 'points'));
        self::assertSame('', $clamp['sections'][0]['blocks'][3]['lowLabel']);

        // Not in the cells of a double-entry table: they are a grid of numbers.
        $matrix = $this->definitions->normalize(['sections' => [['title' => 'x', 'blocks' => [
            ['type' => 'table', 'mode' => 'matrix', 'cell' => ['fieldType' => 'scale']],
        ]]]]);
        self::assertSame('number', $matrix['sections'][0]['blocks'][0]['cell']['fieldType']);
    }

    public function testOnlyAWholeNumberFromOneToTheTopIsAnAnswer(): void
    {
        $def = $this->definition();
        $key = $def['sections'][0]['blocks'][0]['key'];

        foreach ([[4, 4], ['4', 4], [5.0, 5], [1, 1]] as [$typed, $kept]) {
            self::assertSame($kept, $this->data->normalize($def, ['fields' => [$key => $typed]])['fields'][$key] ?? null, var_export($typed, true));
        }
        foreach ([0, 6, 3.5, 'x', '', true, [3], -1] as $typed) {
            self::assertArrayNotHasKey($key, $this->data->normalize($def, ['fields' => [$key => $typed]])['fields'], var_export($typed, true));
        }
    }

    public function testItIsRequiredLikeAnyFieldAndReadsAsPointsOutOfTheTop(): void
    {
        $def   = $this->definition();
        $key   = $def['sections'][0]['blocks'][0]['key'];
        $codes = array_column($this->validator->problems($def, $this->data->normalize($def, [])), 'code');
        self::assertContains('required', $codes);

        foreach ([[4, '4 / 5'], [1, '1 / 5 (Nada)'], [5, '5 / 5 (Mucho)']] as [$answer, $text]) {
            $view = $this->resolver->resolve($def, $this->data->normalize($def, ['fields' => [$key => $answer]]));
            self::assertSame($text, $view[0]['blocks'][0]['items'][0]['value']);
            self::assertArrayNotHasKey('scale', $view[0]['blocks'][0]['items'][0]);
        }
    }

    public function testWithoutAnAnswerItPrintsItsPointsToTickOnPaper(): void
    {
        $view = $this->resolver->resolve($this->definition(), []);
        self::assertSame('Nada  ○ 1   ○ 2   ○ 3   ○ 4   ○ 5  Mucho', $view[0]['blocks'][0]['items'][0]['scale']);
        self::assertSame('○ 1   ○ 2   ○ 3', FormResolver::scalePoints(['points' => 3]));
    }

    public function testATableColumnOfRatingsTotalsAndFeedsFormulasAsNumbers(): void
    {
        $def    = $this->definitions->normalize(['sections' => [['title' => 'x', 'blocks' => [
            ['type' => 'table', 'label' => 'Preguntas', 'mode' => 'free', 'columns' => [
                ['label' => 'Valoración', 'fieldType' => 'scale', 'points' => 4],
                ['label' => 'Doble', 'fieldType' => 'calculated', 'formula' => 'PLACEHOLDER'],
            ]],
            ['type' => 'field', 'fieldType' => 'calculated', 'label' => 'Media', 'formula' => 'PLACEHOLDER'],
        ]]]]);
        $table  = $def['sections'][0]['blocks'][0];
        [$rating, $double] = array_column($table['columns'], 'key');
        $def['sections'][0]['blocks'][0]['columns'][1]['formula'] = '[r:' . $rating . '] * 2';
        $def['sections'][0]['blocks'][1]['formula']               = 'avg([t:' . $table['key'] . '.' . $rating . '])';

        $data = $this->data->normalize($def, ['tables' => [$table['key'] => [['_key' => 'a', $rating => '3'], ['_key' => 'b', $rating => 4], ['_key' => 'c', $rating => 9]]]]);
        $rows = $data['tables'][$table['key']];
        self::assertSame([6.0, 8.0, null], array_map(static fn (array $r): mixed => $r[$double] ?? null, $rows), 'a rating out of the scale is no answer');
        self::assertEqualsWithDelta(3.5, $data['fields'][$def['sections'][0]['blocks'][1]['key']], 1e-9);
    }

    public function testItIsIndexedAsANumber(): void
    {
        self::assertSame([4.0, null, null], FormValueIndexer::typed('scale', 4));
    }
}
