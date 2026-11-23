<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormAttachments;
use App\Service\Form\FormCalculator;
use App\Service\Form\FormDataNormalizer;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormEvaluator;
use App\Service\Form\FormWalker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** The fields «Adjuntos» in the definition and the data, and the walk over their values (copies of repeating sections included). */
final class FormAttachmentsTest extends TestCase
{
    private FormDefinitionNormalizer $definitions;
    private FormDataNormalizer $data;

    protected function setUp(): void
    {
        $sanitizer         = new HtmlSanitizer(new HtmlSanitizerConfig());
        $this->definitions = new FormDefinitionNormalizer($sanitizer);
        $this->data        = new FormDataNormalizer($sanitizer, new FormCalculator(new FormWalker(), new FormEvaluator()));
    }

    /** @return array<string, mixed> a field «Adjuntos» on the document and another in a section repeated by hand */
    private function definition(): array
    {
        return $this->definitions->normalize(['sections' => [
            ['title' => 'Evidencias', 'blocks' => [['type' => 'field', 'fieldType' => 'attachment', 'label' => 'Fotos', 'maxFiles' => 50, 'printPages' => true]]],
            ['title' => 'Visita', 'repeat' => ['mode' => 'manual', 'min' => 0, 'max' => 5], 'blocks' => [['type' => 'field', 'fieldType' => 'attachment', 'label' => 'Acta']]],
        ]]);
    }

    public function testAFieldTakesUpToTenFilesAndATableCellNone(): void
    {
        $field = $this->definition()['sections'][0]['blocks'][0];
        self::assertSame([10, true], [$field['maxFiles'], $field['printPages']]);
        self::assertSame(5, $this->definition()['sections'][1]['blocks'][0]['maxFiles'], 'five when the model says nothing');

        $table = $this->definitions->normalize(['sections' => [['title' => 'x', 'blocks' => [
            ['type' => 'table', 'mode' => 'free', 'columns' => [['label' => 'Archivo', 'fieldType' => 'attachment']]],
        ]]]]);
        self::assertSame('short', $table['sections'][0]['blocks'][0]['columns'][0]['fieldType']);
    }

    public function testTheValueIsAListOfFilesByIdWithoutRepeatsOrStrangers(): void
    {
        $def = $this->definition();
        $key = $def['sections'][0]['blocks'][0]['key'];
        $id  = FormDefinition::newKey();
        $out = $this->data->normalize($def, ['fields' => [$key => [
            ['id' => strtoupper($id), 'name' => '<b>acta</b>.pdf', 'size' => -3, 'mime' => 'application/pdf'],
            ['id' => $id, 'name' => 'repetido'],
            ['id' => '../../etc/passwd', 'name' => 'x'],
            'no es un archivo',
        ]]]);

        self::assertSame([['id' => $id, 'name' => 'acta.pdf', 'size' => 0, 'mime' => 'application/pdf']], $out['fields'][$key]);
        self::assertArrayNotHasKey($key, $this->data->normalize($def, ['fields' => [$key => []]])['fields']);
    }

    public function testTheWalkReachesTheCopiesOfARepeatingSection(): void
    {
        $def    = $this->definition();
        [$a, $b] = [FormDefinition::newKey(), FormDefinition::newKey()];
        $top    = $def['sections'][0]['blocks'][0]['key'];
        $inCopy = $def['sections'][1]['blocks'][0]['key'];
        $copies = $def['sections'][1]['key'];
        $data   = $this->data->normalize($def, [
            'fields' => [$top => [['id' => $a, 'name' => 'a.png']]],
            'copies' => [$copies => [['_key' => FormDefinition::newKey(), 'fields' => [$inCopy => [['id' => $b, 'name' => 'b.pdf']]]]]],
        ]);

        self::assertSame([$a, $b], FormAttachments::ids($def, $data));

        // Taking one out everywhere it is.
        $without = FormAttachments::map($def, $data, static fn (array $files): array => array_values(array_filter($files, static fn (array $f): bool => $f['id'] !== $b)));
        self::assertSame([$a], FormAttachments::ids($def, $without));
        self::assertArrayNotHasKey($inCopy, $without['copies'][$copies][0]['fields'], 'an empty field is removed, not left as []');
    }

    public function testSizesReadAsPeopleSayThem(): void
    {
        self::assertSame(['512 B', '230 KB', '1,4 MB'], [FormAttachments::size(512), FormAttachments::size(235520), FormAttachments::size(1468006)]);
        self::assertTrue(FormAttachments::isImage('image/png'));
        self::assertFalse(FormAttachments::isImage('application/pdf'));
    }
}
