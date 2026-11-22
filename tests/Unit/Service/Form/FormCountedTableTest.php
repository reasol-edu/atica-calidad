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
 * A table with as many rows as a number field says: the rows follow the number (the first ones are kept, blank ones are added, the
 * last ones go), whoever fills in cannot add or remove them, and a model that points at a field that cannot work is reported.
 */
final class FormCountedTableTest extends TestCase
{
    private FormDefinitionNormalizer $definitions;
    private FormDataNormalizer $data;
    private FormDataValidator $validator;
    private FormResolver $resolver;

    private const string COUNT = 'a0000000-0000-4000-8000-000000000001';
    private const string TABLE = 'a0000000-0000-4000-8000-000000000002';
    private const string DATE  = 'a0000000-0000-4000-8000-000000000003';
    private const string TOPIC = 'a0000000-0000-4000-8000-000000000004';

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

    /**
     * «Reuniones celebradas» (a number) in one section and, in another, «Fechas» with a row for each, a required date and a topic.
     *
     * @param array<string, mixed> $table what to add to or change in the table block
     *
     * @return array<string, mixed>
     */
    private function definition(array $table = [], string $countType = 'number'): array
    {
        return $this->definitions->normalize(['sections' => [
            ['title' => 'Datos', 'blocks' => [['key' => self::COUNT, 'type' => 'field', 'fieldType' => $countType, 'label' => 'Reuniones celebradas']]],
            ['title' => 'Fechas', 'blocks' => [$table + [
                'key' => self::TABLE, 'type' => 'table', 'label' => 'Fechas de reunión', 'mode' => 'counted', 'rowsField' => self::COUNT,
                'columns' => [
                    ['key' => self::DATE, 'label' => 'Fecha', 'fieldType' => 'date', 'required' => true],
                    ['key' => self::TOPIC, 'label' => 'Asunto', 'fieldType' => 'short'],
                ],
            ]]],
        ]]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $data): array
    {
        $rows = $data['tables'][self::TABLE] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    public function testTheTableHasAsManyRowsAsTheNumberSays(): void
    {
        $def = $this->definition();

        self::assertEquals(['mode' => 'counted', 'rowsField' => self::COUNT, 'maxRows' => 100, 'minRows' => 0], array_intersect_key($def['sections'][1]['blocks'][0], array_flip(['mode', 'rowsField', 'maxRows', 'minRows'])));
        self::assertCount(0, $this->rows($this->data->initial($def)), 'with no number there are no rows');
        self::assertCount(3, $this->rows($this->data->normalize($def, ['fields' => [self::COUNT => 3]])));
        self::assertCount(2, $this->rows($this->data->normalize($def, ['fields' => [self::COUNT => '2,9']])), 'a decimal number counts its whole part');
        self::assertCount(0, $this->rows($this->data->normalize($def, ['fields' => [self::COUNT => -4]])));
    }

    public function testTheCapOfTheTableAndTheAbsoluteOneLimitTheRows(): void
    {
        self::assertCount(5, $this->rows($this->data->normalize($this->definition(['maxRows' => 5]), ['fields' => [self::COUNT => 50]])));
        self::assertCount(FormDefinition::MAX_COUNTED_ROWS, $this->rows($this->data->normalize($this->definition(['maxRows' => 5000]), ['fields' => [self::COUNT => 5000]])));
        self::assertCount(FormDefinition::MAX_COUNTED_ROWS, $this->rows($this->data->normalize($this->definition(), ['fields' => [self::COUNT => 5000]])), 'no cap means the absolute one');
    }

    public function testRaisingTheNumberKeepsTheTypedRowsAndLoweringItDropsTheLastOnes(): void
    {
        $def   = $this->definition();
        $three = $this->data->normalize($def, ['fields' => [self::COUNT => 3]]);
        $rows  = $this->rows($three);
        $keys  = array_column($rows, '_key');
        self::assertCount(3, array_unique($keys));

        $three['tables'][self::TABLE][0][self::TOPIC] = 'Primera';
        $three['tables'][self::TABLE][2][self::TOPIC] = 'Tercera';

        $more = $this->data->normalize($def, ['fields' => [self::COUNT => 5]] + $three);
        self::assertSame($keys, array_slice(array_column($this->rows($more), '_key'), 0, 3), 'the first rows keep their keys');
        self::assertSame('Tercera', $this->rows($more)[2][self::TOPIC]);
        self::assertCount(5, $this->rows($more));

        $less = $this->data->normalize($def, ['fields' => [self::COUNT => 1]] + $more);
        self::assertSame([$keys[0]], array_column($this->rows($less), '_key'));
        self::assertSame('Primera', $this->rows($less)[0][self::TOPIC]);
    }

    public function testRowsTheClientSendsBeyondTheNumberAreIgnored(): void
    {
        $def  = $this->definition();
        $data = $this->data->normalize($def, [
            'fields' => [self::COUNT => 1],
            'tables' => [self::TABLE => [['_key' => 'b0000000-0000-4000-8000-000000000001', self::TOPIC => 'Sí'], ['_key' => 'b0000000-0000-4000-8000-000000000002', self::TOPIC => 'No']]],
        ]);

        self::assertCount(1, $this->rows($data));
        self::assertSame('Sí', $this->rows($data)[0][self::TOPIC]);
    }

    public function testBlankRowsAreNotDroppedAtDeliveryBecauseTheyAreWhatWasAskedFor(): void
    {
        $def  = $this->definition();
        $data = $this->data->normalize($def, ['fields' => [self::COUNT => 2]]);

        self::assertCount(2, $this->rows($this->data->withoutEmptyRows($def, $data)));
    }

    public function testTheRowsAreValidatedLikeAnyOther(): void
    {
        $def  = $this->definition();
        $data = $this->data->normalize($def, ['fields' => [self::COUNT => 2]]);
        $data['tables'][self::TABLE][0][self::DATE] = '2027-03-01';

        $problems = $this->validator->problems($def, $data);
        $required = array_filter($problems, static fn (array $p): bool => $p['code'] === 'required' && $p['column'] === self::DATE);
        self::assertCount(1, $required, 'only the row without a date is reported');
        self::assertEmpty(array_filter($problems, static fn (array $p): bool => in_array($p['code'], ['rows_min', 'rows_max'], true)));
    }

    public function testRowsAreNumberedInTheView(): void
    {
        $def  = $this->definition(['rowHeader' => 'Reunión']);
        $view = $this->resolver->resolve($def, $this->data->normalize($def, ['fields' => [self::COUNT => 2]]));
        $table = $view[1]['blocks'][0];

        self::assertTrue($table['fixed']);
        self::assertSame('Reunión', $table['rowHeader']);
        self::assertSame(['1', '2'], array_column($table['rows'], 'label'));
    }

    public function testAModelThatPointsAtAFieldThatCannotWorkIsReported(): void
    {
        self::assertNotContains('counted_table_invalid', array_column($this->definitions->problems($this->definition()), 'code'));
        self::assertContains('counted_table_invalid', array_column($this->definitions->problems($this->definition([], 'short')), 'code'), 'a text is not a number');
        self::assertContains('counted_table_invalid', array_column($this->definitions->problems($this->definition(['rowsField' => 'a0000000-0000-4000-8000-0000000000ff'])), 'code'), 'a field that does not exist');
        self::assertContains('counted_table_invalid', array_column($this->definitions->problems($this->definition(['rowsField' => 'no es una clave'])), 'code'));
    }
}
