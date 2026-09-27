<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The "app.rich_text" sanitizer (config/packages/html_sanitizer.yaml) keeps a paragraph's
 * text-align — the one thing Quill's align toolbar button writes as inline style
 * (rich_editor_controller.js) — while still dropping every other style declaration and every
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
}
