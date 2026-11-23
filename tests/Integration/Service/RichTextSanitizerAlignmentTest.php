<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The "app.rich_text" sanitizer (config/packages/html_sanitizer.yaml) keeps a paragraph's
 * text-align — the one thing the Tiptap editor's align buttons write as inline style
 * (assets/utils/rich_text_editor.js) — while still dropping every other style declaration and every
 * class/id attribute everywhere else, exactly as before this was added.
 */
final class RichTextSanitizerAlignmentTest extends RepositoryTestCase
{
    private HtmlSanitizerInterface $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HtmlSanitizerInterface $sanitizer */
        $sanitizer       = self::getContainer()->get('html_sanitizer.sanitizer.app.rich_text');
        $this->sanitizer = $sanitizer;
    }

    public function testKeepsAnAllowedTextAlignValueOnAParagraph(): void
    {
        $result = $this->sanitizer->sanitize('<p style="text-align: center;">Centrado</p>');

        self::assertSame('<p style="text-align: center;">Centrado</p>', $result);
    }

    public function testStripsAnyOtherDeclarationSharingTheSameStyleAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<p style="text-align: right; background: url(https://evil.example/track.png);">Ojo</p>');

        self::assertSame('<p style="text-align: right;">Ojo</p>', $result);
    }

    public function testDropsTheStyleAttributeEntirelyWhenItHasNoRecognisedAlignment(): void
    {
        $result = $this->sanitizer->sanitize('<p style="background: url(https://evil.example/track.png);">Ojo</p>');

        self::assertSame('<p>Ojo</p>', $result);
    }

    public function testStyleIsNeverAllowedOnAnElementOutsideTheAlignableList(): void
    {
        $result = $this->sanitizer->sanitize('<strong style="text-align: center;">Negrita</strong>');

        self::assertSame('<strong>Negrita</strong>', $result);
    }

    public function testClassAndIdStayDroppedEverywhereIncludingTheNewlyAllowedElements(): void
    {
        $result = $this->sanitizer->sanitize('<p class="ql-align-center" id="x" style="text-align: center;">Hola</p>');

        self::assertSame('<p style="text-align: center;">Hola</p>', $result);
    }

    /** What the Tiptap editor writes: paragraphs inside the list items, a link with the attributes of its schema, an aligned heading. */
    public function testTheHtmlOfTheTiptapEditorSurvivesTheSanitizer(): void
    {
        $html = '<h2 style="text-align: center">Título</h2><h3 style="text-align: right" class="x">Subtítulo</h3><ul><li><p>Uno <strong>fuerte</strong></p></li><li><p style="text-align: right">Dos</p></li></ul>'
            . '<ol><li><p>Tres</p></li></ol><blockquote><p>Cita</p></blockquote><p><a href="https://ejemplo.org" rel="noopener noreferrer nofollow" target="_blank" class="x">enlace</a> <u>sub</u> <em>cur</em></p>';
        $result = $this->sanitizer->sanitize($html);

        self::assertStringContainsString('<h2 style="text-align: center;">Título</h2><h3 style="text-align: right;">Subtítulo</h3>', $result);
        self::assertStringContainsString('<ul><li><p>Uno <strong>fuerte</strong></p></li><li><p style="text-align: right;">Dos</p></li></ul>', $result);
        self::assertStringContainsString('<ol><li><p>Tres</p></li></ol><blockquote><p>Cita</p></blockquote>', $result);
        self::assertStringContainsString('<a href="https://ejemplo.org" rel="noopener noreferrer">enlace</a>', $result);
        self::assertStringNotContainsString('target', $result);
        self::assertStringNotContainsString('class', $result);
    }

    public function testTheListItemsWithParagraphsReadAsBulletLinesInThePlainText(): void
    {
        self::assertSame(['Título', '• Uno', '• Dos', 'Fin'], \App\Service\Form\FormOfficeWriter::paragraphs('<h2>Título</h2><ul><li><p>Uno</p></li><li><p>Dos</p></li></ul><p>Fin</p>'));
    }
}
