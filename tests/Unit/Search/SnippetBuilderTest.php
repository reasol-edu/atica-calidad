<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\SnippetBuilder;
use App\Search\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class SnippetBuilderTest extends TestCase
{
    /**
     * @param list<string> $terms
     *
     * @return list<array{0: string, 1: bool}>|null
     */
    private function snippet(string $text, array $terms, int $radius = 70): ?array
    {
        $text = TextNormalizer::clean($text);

        return SnippetBuilder::build($text, TextNormalizer::fold($text), $terms, $radius);
    }

    /**
     * @param list<array{0: string, 1: bool}> $parts
     *
     * @return list<string>
     */
    private static function markedWords(array $parts): array
    {
        return array_values(array_map(static fn (array $p): string => $p[0], array_filter($parts, static fn (array $p): bool => $p[1])));
    }

    public function testTheMatchKeepsTheAccentsAndCaseOfTheOriginalText(): void
    {
        $parts = $this->snippet('El Protocolo de Convivencia del centro, en la Educación Física.', ['educacion']);

        self::assertNotNull($parts);
        self::assertSame(['Educación'], self::markedWords($parts));
    }

    public function testNoTermInTheTextGivesNothing(): void
    {
        self::assertNull($this->snippet('Nada que ver aquí.', ['protocolo']));
    }

    public function testAMatchInTheMiddleOfALongTextIsFramedWithEllipses(): void
    {
        $text  = str_repeat('palabra ', 100) . 'protocolo de acoso ' . str_repeat('relleno ', 100);
        $parts = $this->snippet($text, ['protocolo']);

        self::assertNotNull($parts);
        self::assertSame('…', $parts[0][0]);
        self::assertSame('…', $parts[array_key_last($parts)][0]);
        self::assertSame(['protocolo'], self::markedWords($parts));
        self::assertLessThan(260, mb_strlen(implode('', array_map(static fn (array $p): string => $p[0], $parts))));
    }

    public function testEveryTermInTheWindowIsMarkedAndAdjacentOnesMerge(): void
    {
        $parts = $this->snippet('Plan de acogida del alumnado nuevo y plan de convivencia', ['plan', 'acogida']);

        self::assertNotNull($parts);
        self::assertSame(['Plan', 'acogida', 'plan'], self::markedWords($parts));
    }

    public function testASnippetThatStartsAtTheBeginningHasNoLeadingEllipsis(): void
    {
        $parts = $this->snippet('Protocolo de convivencia', ['protocolo']);

        self::assertNotNull($parts);
        self::assertSame('Protocolo', $parts[0][0]);
        self::assertTrue($parts[0][1]);
    }

    public function testTheWindowDoesNotCutAWordInHalfAtItsEdges(): void
    {
        $text  = str_repeat('abcdefghij ', 30) . 'protocolo ' . str_repeat('abcdefghij ', 30);
        $parts = $this->snippet($text, ['protocolo'], 25);

        self::assertNotNull($parts);
        $joined = implode('', array_map(static fn (array $p): string => $p[0], $parts));
        foreach (explode(' ', trim($joined, '… ')) as $word) {
            self::assertContains($word, ['abcdefghij', 'protocolo'], 'a word was cut: ' . $word);
        }
    }
}
