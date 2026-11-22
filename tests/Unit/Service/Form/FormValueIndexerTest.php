<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Entity\FormValue;
use App\Service\Form\FormValueIndexer;
use PHPUnit\Framework\TestCase;

final class FormValueIndexerTest extends TestCase
{
    public function testAMultipleChoiceKeepsItsKeysJoinedByCommas(): void
    {
        [$num, $text, $flag] = FormValueIndexer::typed('multichoice', ['a', 'b', 'c']);
        self::assertNull($num);
        self::assertSame('a,b,c', $text);
        self::assertNull($flag);
    }

    public function testTooManyKeysAreCutBetweenKeysNeverInTheMiddleOfOne(): void
    {
        $keys = [];
        for ($i = 0; $i < 40; ++$i) {
            $keys[] = sprintf('%08x-0000-4000-8000-%012x', $i, $i);
        }
        [, $text] = FormValueIndexer::typed('multichoice', $keys);

        self::assertNotNull($text);
        self::assertLessThanOrEqual(FormValue::TEXT_LENGTH, mb_strlen($text));
        // 27 keys of 36 characters and their 26 commas fit in 1000; the 28th would not.
        self::assertSame(27, \count(explode(',', $text)));
        foreach (explode(',', $text) as $key) {
            self::assertSame(36, mb_strlen($key), 'only whole keys');
        }
    }

    public function testEmptyValuesAreNotIndexed(): void
    {
        self::assertSame([null, null, null], FormValueIndexer::typed('multichoice', []));
        self::assertSame([null, null, null], FormValueIndexer::typed('short', ''));
    }
}
