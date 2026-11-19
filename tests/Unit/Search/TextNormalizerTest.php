<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testFoldRemovesAccentsAndLowerCasesKeepingTheLength(): void
    {
        $text = 'Educación Física: ÑANDÚ, Güeñes, pingüino';

        $folded = TextNormalizer::fold($text);

        self::assertSame('educacion fisica: nandu, guenes, pinguino', $folded);
        self::assertSame(mb_strlen($text), mb_strlen($folded));
    }

    public function testFoldKeepsTheLengthEvenWhenTheAccentsComeDecomposed(): void
    {
        // "ó" as "o" + combining acute (what macOS file names and some editors produce).
        $text = "Programacio\u{0301}n did\u{00E1}ctica";

        // Whatever comes in, the folded text has as many characters as the text it is folded from...
        self::assertSame(mb_strlen($text), mb_strlen(TextNormalizer::fold($text)));

        // ...and the text the index stores goes through clean() first, which composes the accents, so they do fold away.
        $clean = TextNormalizer::clean($text);
        self::assertSame(mb_strlen($clean), mb_strlen(TextNormalizer::fold($clean)));
        self::assertSame('programacion didactica', TextNormalizer::fold($clean));
    }

    public function testCleanDropsNulAndControlCharactersAndCollapsesWhitespace(): void
    {
        $dirty = "Acta\0 de la\x07 reunión\r\n\r\n  de   mayo\t\u{00A0}del curso ";

        self::assertSame('Acta de la reunión de mayo del curso', TextNormalizer::clean($dirty));
    }

    public function testCleanReplacesInvalidUtf8InsteadOfKeepingIt(): void
    {
        $clean = TextNormalizer::clean("Ni\xF1o");

        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
    }

    public function testCleanComposesTheAccents(): void
    {
        self::assertSame("educaci\u{00F3}n", TextNormalizer::clean("educacio\u{0301}n"));
    }

    public function testTermsAreFoldedDeduplicatedAndInOrder(): void
    {
        self::assertSame(['programacion', 'didactica', 'matematicas'], TextNormalizer::terms('  Programación  DIDÁCTICA programación Matemáticas '));
        self::assertSame([], TextNormalizer::terms('   '));
    }

    public function testLikeContainsEscapesTheWildcards(): void
    {
        self::assertSame('%50!%!_!!%', TextNormalizer::likeContains('50%_!'));
    }
}
