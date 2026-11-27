<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A line «key:'text'» (no space after the colon) is valid YAML — one long plain key with no value — so `lint:yaml` lets it through,
 * and the page shows the bare key instead of the text. Every translation line with a quoted value has its space.
 */
final class TranslationFilesTest extends TestCase
{
    public function testEveryKeyHasASpaceBeforeItsQuotedText(): void
    {
        $broken = [];
        foreach (glob(\dirname(__DIR__, 2) . '/translations/*.yaml') ?: [] as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match('/^[A-Za-z0-9_.\-]+:[\'"]/', $line) === 1) {
                    $broken[] = basename($file) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $broken, 'add the space after the colon');
    }
}
