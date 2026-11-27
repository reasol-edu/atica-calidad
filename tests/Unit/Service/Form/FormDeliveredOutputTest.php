<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormDiff;
use App\Service\Form\FormResolver;
use App\Service\Form\FormWalker;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A table of deliveries in the delivered document (PDF, Word, OpenDocument and the page of a revision): the state of each delivery first
 * when the model asks for it, «—» where a delivery has nothing, where the rows came from under it, and the history of changes naming the
 * rows after each delivery and not counting the «—».
 */
final class FormDeliveredOutputTest extends TestCase
{
    private const string TABLE = 'b0000000-0000-4000-8000-000000000001';
    private const string HOURS = 'b0000000-0000-4000-8000-000000000002';
    private const string OWN   = 'b0000000-0000-4000-8000-000000000003';
    private const string PROG  = 'b0000000-0000-4000-8000-000000000004';
    private const string BASES = 'b0000000-0000-4000-8000-000000000005';

    private FormResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FormResolver(new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr(match ($id) {
                    'fill.delivered_status'           => 'Estado',
                    'fill.delivered_status_delivered' => 'Entregada',
                    'fill.delivered_status_none'      => 'Sin empezar',
                    'fill.delivered_approval_approved' => 'Aprobada',
                    'view.delivered_from'             => 'Datos de «%title%» a %at%.',
                    default                           => $id,
                }, array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $parameters));
            }

            public function getLocale(): string
            {
                return 'es';
            }
        }, new FormWalker());
    }

    /** @return array<string, mixed> */
    private function definition(bool $status = true): array
    {
        return ['version' => 1, 'sections' => [[
            'key' => 'b0000000-0000-4000-8000-000000000010', 'title' => 'Materias', 'numbered' => false, 'children' => [],
            'blocks' => [[
                'key' => self::TABLE, 'type' => 'table', 'mode' => 'delivered', 'label' => 'Revisión', 'showStatus' => $status,
                'rows' => [['key' => self::PROG, 'label' => 'Programación'], ['key' => self::BASES, 'label' => 'Bases de datos']],
                'columns' => [
                    ['key' => self::HOURS, 'label' => 'Horas', 'fieldType' => 'number', 'sourceField' => 'horas', 'total' => 'sum'],
                    ['key' => self::OWN, 'label' => 'Valoración', 'fieldType' => 'short'],
                ],
            ]],
        ]]];
    }

    /** @return array<string, mixed> */
    private function data(int $hours, bool $basesDelivered = false): array
    {
        return [
            'tables'  => [self::TABLE => [
                ['_key' => self::PROG, self::HOURS => $hours, self::OWN => 'Bien'],
                ['_key' => self::BASES, self::HOURS => $basesDelivered ? 20 : null, self::OWN => ''],
            ]],
            'sources' => [self::TABLE => ['activity' => 'x', 'title' => 'Revisión de la programación', 'at' => '2026-12-03T10:42:00+01:00', 'rows' => [
                ['key' => self::PROG, 'label' => 'Programación', 'status' => 'delivered', 'approval' => 'approved'],
                ['key' => self::BASES, 'label' => 'Bases de datos', 'status' => $basesDelivered ? 'delivered' : 'none', 'approval' => null],
            ]]],
        ];
    }

    /** @return array<string, mixed> */
    private function table(array $definition, array $data): array
    {
        $block = $this->resolver->resolve($definition, $data)[0]['blocks'][0] ?? null;
        self::assertIsArray($block);

        return $block;
    }

    public function testTheStateComesFirstAndADeliveryWithNothingShowsADash(): void
    {
        $table = $this->table($this->definition(), $this->data(30));

        self::assertSame(['Estado', 'Horas', 'Valoración'], $table['columns']);
        self::assertSame(['Entregada · Aprobada', '30', 'Bien'], $table['rows'][0]['cells']);
        self::assertSame(['Sin empezar', '—', ''], $table['rows'][1]['cells'], 'an own column left empty stays empty');
        self::assertSame(['Programación', 'Bases de datos'], array_column($table['rows'], 'label'));
        self::assertSame(['', '30', ''], $table['totals'], 'the totals move with the columns');
        self::assertSame('Datos de «Revisión de la programación» a 03/12/2026 10:42.', $table['deliveredFrom']['text']);
    }

    public function testWithoutStateTheColumnsAreTheModels(): void
    {
        $table = $this->table($this->definition(false), $this->data(30));

        self::assertSame(['Horas', 'Valoración'], $table['columns']);
        self::assertSame(['—', ''], $table['rows'][1]['cells']);
    }

    public function testAColumnThatReadsARichTextKeepsItsFormattingInTheDocument(): void
    {
        $notes      = 'b0000000-0000-4000-8000-000000000020';
        $definition = $this->definition();
        $definition['sections'][0]['blocks'][0]['columns'][] = ['key' => $notes, 'label' => 'Observaciones', 'fieldType' => 'long', 'sourceField' => 'observaciones'];
        $data = $this->data(30);
        $data['tables'][self::TABLE][0][$notes] = '<p>Bien, <strong>salvo</strong> la unidad 5.</p>';

        $table = $this->table($definition, $data);
        self::assertSame('Bien, salvo la unidad 5.', $table['rows'][0]['cells'][3], 'the plain text, for Word and the history');
        self::assertSame([3 => '<p>Bien, <strong>salvo</strong> la unidad 5.</p>'], $table['rows'][0]['html'], 'its HTML, by the index of the cell (after «Estado»)');
        self::assertSame([], $table['rows'][1]['html'], 'nothing read: the dash');
        self::assertSame('—', $table['rows'][1]['cells'][3]);
    }

    public function testTheDraftKeepsTheSanitizedHtmlOfASourceRichTextButAnOwnLongCellStaysPlain(): void
    {
        $sanitizer  = new \Symfony\Component\HtmlSanitizer\HtmlSanitizer((new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig())->allowElement('p')->allowElement('strong'));
        $normalizer = new \App\Service\Form\FormDataNormalizer($sanitizer, new \App\Service\Form\FormCalculator(new FormWalker(), new \App\Service\Form\FormEvaluator()));

        self::assertSame('<p>Bien, <strong>salvo</strong> la 5.</p>', $normalizer->coerce(['fieldType' => 'long', 'sourceField' => 'observaciones'], '<p onclick="x()">Bien, <strong>salvo</strong> la 5.</p><script>x()</script>'));
        self::assertSame('Bien, salvo la 5.', $normalizer->coerce(['fieldType' => 'long'], '<p>Bien, <strong>salvo</strong> la 5.</p>'), 'an own column of a table: plain text');
    }

    public function testTheHistoryNamesTheRowsAfterEachDeliveryAndADashIsNothing(): void
    {
        $diff    = new FormDiff($this->resolver);
        $changes = $diff->compare($this->definition(), $this->data(30), $this->definition(), $this->data(25, true));

        self::assertSame([
            ['label' => 'Materias › Revisión › Programación › Horas', 'before' => '30', 'after' => '25', 'kind' => 'changed'],
            ['label' => 'Materias › Revisión › Bases de datos › Estado', 'before' => 'Sin empezar', 'after' => 'Entregada', 'kind' => 'changed'],
            ['label' => 'Materias › Revisión › Bases de datos › Horas', 'before' => null, 'after' => '20', 'kind' => 'changed'],
        ], $changes);
    }
}
