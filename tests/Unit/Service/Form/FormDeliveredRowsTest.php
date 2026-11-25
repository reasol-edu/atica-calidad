<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormDeliveredRows;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** The pure parts of the tables of deliveries: the definition, the values read, and how they go into a draft and are compared. */
final class FormDeliveredRowsTest extends TestCase
{
    private const string TABLE = '0193c7a2-7c1d-7000-8000-000000000001';
    private const string HOURS = '0193c7a2-7c1d-7000-8000-000000000002';
    private const string OWN   = '0193c7a2-7c1d-7000-8000-000000000003';
    private const string MODEL = '0193c7a2-7c1d-7000-8000-000000000004';

    /** @return array<string, mixed> */
    private function normalized(array $table): array
    {
        $normalizer = new FormDefinitionNormalizer(new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()));
        $definition = $normalizer->normalize(['version' => 1, 'sections' => [['key' => '0193c7a2-7c1d-7000-8000-0000000000a0', 'title' => 'S', 'blocks' => [$table + ['key' => self::TABLE, 'type' => 'table', 'label' => 'Resultados']]]]]);

        return $definition['sections'][0]['blocks'][0];
    }

    public function testATableOfDeliveriesHasNoRowsAndItsColumnsOfTheDeliveryAreNeverTyped(): void
    {
        $block = $this->normalized([
            'mode' => 'delivered', 'sourceModel' => strtoupper(self::MODEL), 'rows' => [['key' => self::OWN, 'label' => 'x']], 'minRows' => 3, 'maxRows' => 9,
            'columns' => [
                ['key' => self::HOURS, 'label' => 'Horas', 'fieldType' => 'number', 'sourceField' => 'Horas_Previstas', 'required' => true, 'min' => 1, 'max' => 5, 'maxDecimals' => 0, 'displayDecimals' => 9, 'unit' => 'h', 'total' => 'sum'],
                ['key' => self::OWN, 'label' => 'Valoración', 'fieldType' => 'long', 'required' => true],
                ['label' => 'Elegida', 'fieldType' => 'choice', 'sourceField' => 'opcion', 'options' => [['label' => 'A']]],
            ],
        ]);

        self::assertSame('delivered', $block['mode']);
        self::assertSame([], $block['rows']);
        self::assertSame(0, $block['minRows']);
        self::assertSame(0, $block['maxRows']);
        self::assertSame(self::MODEL, $block['sourceModel']);
        self::assertSame('own', $block['sourceScope'], 'by default, the deliveries of who delivers');
        self::assertTrue($block['showStatus']);

        [$hours, $own, $choice] = $block['columns'];
        self::assertSame('horas_previstas', $hours['sourceField']);
        self::assertFalse($hours['required']);
        self::assertNull($hours['min']);
        self::assertNull($hours['max']);
        self::assertNull($hours['maxDecimals']);
        self::assertSame(6, $hours['displayDecimals']);
        self::assertSame('h', $hours['unit']);
        self::assertSame('sum', $hours['total']);
        self::assertSame('', $own['sourceField']);
        self::assertTrue($own['required'], 'an own column is a column like any other');
        self::assertNull($own['displayDecimals']);
        self::assertSame('short', $choice['fieldType'], 'a type that cannot be shown arrives as text');
        self::assertSame([], $choice['options']);
    }

    public function testOutsideATableOfDeliveriesThereIsNothingToRead(): void
    {
        $block = $this->normalized(['mode' => 'free', 'sourceModel' => self::MODEL, 'columns' => [['key' => self::HOURS, 'label' => 'Horas', 'fieldType' => 'number', 'sourceField' => 'horas']]]);

        self::assertArrayNotHasKey('sourceField', $block['columns'][0]);
        self::assertArrayNotHasKey('sourceModel', $block);
    }

    public function testTheValueOfEachTypeOfField(): void
    {
        self::assertSame(12.5, FormDeliveredRows::value(['fieldType' => 'number'], 12.5));
        self::assertSame(80, FormDeliveredRows::value(['fieldType' => 'calculated'], 80));
        self::assertNull(FormDeliveredRows::value(['fieldType' => 'calculated'], true), 'a calculated yes/no is not a number');
        self::assertSame(4, FormDeliveredRows::value(['fieldType' => 'scale'], 4));
        self::assertSame('Bien', FormDeliveredRows::value(['fieldType' => 'short'], 'Bien'));
        self::assertSame("Uno\nDos", FormDeliveredRows::value(['fieldType' => 'long'], '<p>Uno</p><p>Dos</p>'), 'a rich text as plain text');
        self::assertSame('2026-12-03', FormDeliveredRows::value(['fieldType' => 'date'], '2026-12-03'));
        self::assertFalse(FormDeliveredRows::value(['fieldType' => 'bool'], false));
        $choice = ['key' => 'c', 'fieldType' => 'multichoice', 'options' => [['key' => 'a', 'label' => 'Uno'], ['key' => 'b', 'label' => 'Dos']]];
        self::assertSame('Uno, Dos', FormDeliveredRows::value($choice, ['a', 'b']));
        self::assertSame('De la lista', FormDeliveredRows::value(['key' => 'c', 'fieldType' => 'choice', 'options' => []], 'x', ['c' => [['key' => 'x', 'label' => 'De la lista']]]), 'a list option from the snapshot');
        self::assertNull(FormDeliveredRows::value(['fieldType' => 'attachment'], [['id' => 'x']]));
        self::assertNull(FormDeliveredRows::value(['fieldType' => 'number'], null));
    }

    public function testOnlyTheNamedFieldsOfTheDocumentItselfCanBeRead(): void
    {
        $fields = FormDeliveredRows::namedFields(['sections' => [
            ['blocks' => [
                ['type' => 'field', 'key' => 'h', 'name' => 'horas', 'fieldType' => 'number'],
                ['type' => 'field', 'key' => 'n', 'name' => '', 'fieldType' => 'number'],
                ['type' => 'field', 'key' => 'a', 'name' => 'anexo', 'fieldType' => 'attachment'],
                ['type' => 'field', 'key' => 't', 'name' => 'total', 'fieldType' => 'calculated', 'foot' => true],
            ], 'children' => [['blocks' => [['type' => 'field', 'key' => 'd', 'name' => 'dentro', 'fieldType' => 'short']]]]],
            ['repeat' => ['mode' => 'manual'], 'blocks' => [['type' => 'field', 'key' => 'r', 'name' => 'repetido', 'fieldType' => 'short']]],
        ]]);

        self::assertSame(['horas', 'total', 'dentro'], array_keys($fields));
    }

    /** @return array<string, array{sources: array<string, mixed>, values: array<string, array<string, mixed>>, columns: list<string>}> */
    private function reading(array $rows, array $values): array
    {
        return [self::TABLE => ['sources' => ['activity' => self::MODEL, 'title' => 'Revisión', 'at' => '2026-12-03T10:42:00+01:00', 'assumedAll' => false, 'rows' => $rows], 'values' => $values, 'columns' => [self::HOURS]]];
    }

    public function testInjectKeepsWhatWasTypedByRowFollowsTheOrderReadAndDropsTheRowsThatLeft(): void
    {
        $data = ['tables' => [self::TABLE => [
            ['_key' => 'r1', self::HOURS => 10, self::OWN => 'Valoración 1'],
            ['_key' => 'r2', self::HOURS => 20, self::OWN => 'Valoración 2'],
            ['_key' => 'gone', self::OWN => 'Se va'],
        ]]];
        $rows = [['key' => 'r3', 'status' => 'none'], ['key' => 'r2', 'status' => 'delivered'], ['key' => 'r1', 'status' => 'delivered']];

        $after = FormDeliveredRows::inject($data, $this->reading($rows, ['r2' => [self::HOURS => 25]]));

        self::assertSame([
            ['_key' => 'r3'],
            ['_key' => 'r2', self::OWN => 'Valoración 2', self::HOURS => 25],
            ['_key' => 'r1', self::OWN => 'Valoración 1'],
        ], $after['tables'][self::TABLE], 'r1 lost its value (none read now), r2 got the new one, both kept their own text');
        self::assertSame('Revisión', $after['sources'][self::TABLE]['title']);

        $changes = FormDeliveredRows::changes($data, $after, $this->reading($rows, []));
        self::assertSame(4, $changes['count'], 'r3 came, «gone» left, r1 and r2 changed');
        self::assertSame([['table' => self::TABLE, 'row' => 'r2', 'column' => self::HOURS], ['table' => self::TABLE, 'row' => 'r1', 'column' => self::HOURS]], $changes['cells']);
    }

    public function testAChangeOfStateOrApprovalIsAChangeAndOneOfTeachersIsNot(): void
    {
        $rows   = static fn (string $status, ?string $approval, array $teachers): array => [['key' => 'r1', 'status' => $status, 'approval' => $approval, 'teachers' => $teachers]];
        $before = FormDeliveredRows::inject([], $this->reading($rows('delivered', 'pending', ['Ana']), ['r1' => [self::HOURS => 1]]));

        $same = FormDeliveredRows::inject($before, $this->reading($rows('delivered', 'pending', ['Luis']), ['r1' => [self::HOURS => 1]]));
        self::assertSame(0, FormDeliveredRows::changes($before, $same, $this->reading([], []))['count']);

        $approved = FormDeliveredRows::inject($before, $this->reading($rows('delivered', 'approved', ['Ana']), ['r1' => [self::HOURS => 1]]));
        self::assertSame(['count' => 1, 'cells' => []], FormDeliveredRows::changes($before, $approved, $this->reading([], [])));
    }

    public function testTheRowsOfTheDefinitionComeFromTheData(): void
    {
        $definition = ['sections' => [['blocks' => [['type' => 'table', 'key' => self::TABLE, 'mode' => 'delivered', 'rows' => []]], 'children' => []]]];
        $data       = FormDeliveredRows::inject([], $this->reading([['key' => 'r1', 'label' => 'Programación', 'status' => 'delivered', 'approval' => 'approved', 'teachers' => ['Noelia']]], []));

        $rows = FormDeliveredRows::rowsFromData($definition, $data)['sections'][0]['blocks'][0]['rows'];

        self::assertSame([['key' => 'r1', 'label' => 'Programación', 'status' => 'delivered', 'approval' => 'approved', 'revision' => null, 'document' => null, 'teachers' => ['Noelia']]], $rows);
        self::assertSame([], FormDeliveredRows::rowsFromData($definition, [])['sections'][0]['blocks'][0]['rows'], 'nothing read, no rows');
    }

    public function testTheSourcesKeepOnlyTheirShape(): void
    {
        $clean = FormDeliveredRows::clean([
            self::TABLE => ['activity' => 'no', 'title' => 'T', 'at' => 'x', 'assumedAll' => 'yes', 'evil' => 1, 'rows' => [
                ['key' => self::OWN, 'status' => 'hacked', 'approval' => 'approved', 'version' => '2', 'teachers' => ['Ana', 3]],
                ['key' => 'not-a-uuid'],
            ]],
            'not-a-table' => ['rows' => []],
        ]);

        self::assertSame([self::TABLE], array_keys($clean));
        self::assertNull($clean[self::TABLE]['activity']);
        self::assertFalse($clean[self::TABLE]['assumedAll']);
        self::assertArrayNotHasKey('evil', $clean[self::TABLE]);
        self::assertCount(1, $clean[self::TABLE]['rows']);
        self::assertSame('none', $clean[self::TABLE]['rows'][0]['status']);
        self::assertSame('approved', $clean[self::TABLE]['rows'][0]['approval']);
        self::assertNull($clean[self::TABLE]['rows'][0]['version']);
        self::assertSame(['Ana'], $clean[self::TABLE]['rows'][0]['teachers']);
    }

    public function testWhatTheBrowserSendsForTheCellsReadIsReplacedByWhatTheDraftHas(): void
    {
        $definition = ['sections' => [['blocks' => [['type' => 'table', 'key' => self::TABLE, 'mode' => 'delivered', 'columns' => [['key' => self::HOURS, 'sourceField' => 'horas'], ['key' => self::OWN, 'sourceField' => '']]]], 'children' => []]]];
        $saved      = ['tables' => [self::TABLE => [['_key' => 'r1', self::HOURS => 10], ['_key' => 'r2']]], 'sources' => ['s' => 1]];
        $raw        = ['tables' => [self::TABLE => [['_key' => 'r1', self::HOURS => 999, self::OWN => 'Mío'], ['_key' => 'r2', self::HOURS => 5]]], 'sources' => ['forged' => 1]];

        $kept = FormDeliveredRows::keepSaved($definition, $raw, $saved);

        self::assertSame([['_key' => 'r1', self::OWN => 'Mío', self::HOURS => 10], ['_key' => 'r2']], $kept['tables'][self::TABLE]);
        self::assertSame(['s' => 1], $kept['sources']);
    }
}
