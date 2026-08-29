<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CsvReader;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    private CsvReader $reader;

    protected function setUp(): void
    {
        $this->reader = new CsvReader();
    }

    public function testParsesHeadersAndRowsIntoHeaderKeyedAssociativeArrays(): void
    {
        $result = $this->reader->parse("nombre,email\nAna,ana@example.com\nLuis,luis@example.com\n");

        self::assertSame(['nombre', 'email'], $result['headers']);
        self::assertSame([
            ['nombre' => 'Ana', 'email' => 'ana@example.com'],
            ['nombre' => 'Luis', 'email' => 'luis@example.com'],
        ], $result['rows']);
    }

    public function testStripsALeadingUtf8Bom(): void
    {
        $result = $this->reader->parse("\xEF\xBB\xBFnombre\nAna\n");

        self::assertSame(['nombre'], $result['headers']);
        self::assertSame([['nombre' => 'Ana']], $result['rows']);
    }

    public function testConvertsWindows1252ContentToUtf8(): void
    {
        // 'ñ' encoded as Windows-1252 (0xF1) is not valid UTF-8 on its own.
        $content = "nombre\n" . mb_convert_encoding('Muñoz', 'Windows-1252', 'UTF-8') . "\n";

        $result = $this->reader->parse($content);

        self::assertSame([['nombre' => 'Muñoz']], $result['rows']);
    }

    public function testSkipsBlankLines(): void
    {
        $result = $this->reader->parse("nombre\nAna\n\nLuis\n");

        self::assertCount(2, $result['rows']);
    }

    public function testTrimsWhitespaceFromHeadersAndValues(): void
    {
        $result = $this->reader->parse(" nombre , email \n Ana , ana@example.com \n");

        self::assertSame(['nombre', 'email'], $result['headers']);
        self::assertSame([['nombre' => 'Ana', 'email' => 'ana@example.com']], $result['rows']);
    }

    public function testEmptyContentReturnsEmptyHeadersAndRows(): void
    {
        $result = $this->reader->parse('');

        self::assertSame([], $result['headers']);
        self::assertSame([], $result['rows']);
    }

    public function testARowShorterThanTheHeaderRowFillsMissingColumnsWithEmptyStrings(): void
    {
        $result = $this->reader->parse("nombre,email,telefono\nAna,ana@example.com\n");

        self::assertSame(['nombre' => 'Ana', 'email' => 'ana@example.com', 'telefono' => ''], $result['rows'][0]);
    }

    public function testFindMissingColumnReturnsTheFirstRequiredColumnNotPresent(): void
    {
        self::assertSame('email', $this->reader->findMissingColumn(['nombre', 'telefono'], ['nombre', 'email']));
    }

    public function testFindMissingColumnReturnsNullWhenAllRequiredColumnsArePresent(): void
    {
        self::assertNull($this->reader->findMissingColumn(['nombre', 'email', 'telefono'], ['nombre', 'email']));
    }
}
