<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Service\Form\FormValueIndexer;
use PHPUnit\Framework\TestCase;

/**
 * How one value is kept in the index. A multiple choice is not here: it is one row per option chosen, so the indexer calls this once
 * for each (see FormQueryCountTest for the whole thing).
 */
final class FormValueIndexerTest extends TestCase
{
    public function testAKeyIsKeptAsText(): void
    {
        [$num, $text, $flag] = FormValueIndexer::typed('choice', '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b');
        self::assertNull($num);
        self::assertSame('0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b', $text);
        self::assertNull($flag);
    }

    public function testNumbersAndYesNoAreKeptTyped(): void
    {
        self::assertSame([5.0, null, null], FormValueIndexer::typed('number', '5'));
        self::assertSame([1.0, null, true], FormValueIndexer::typed('bool', true));
        self::assertSame([0.0, null, false], FormValueIndexer::typed('bool', false));
    }

    public function testEmptyValuesAreNotIndexed(): void
    {
        self::assertSame([null, null, null], FormValueIndexer::typed('multichoice', []));
        self::assertSame([null, null, null], FormValueIndexer::typed('short', ''));
        self::assertSame([null, null, null], FormValueIndexer::typed('short', null));
    }
}
