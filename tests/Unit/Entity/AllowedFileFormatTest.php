<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AllowedFileFormat;
use PHPUnit\Framework\TestCase;

final class AllowedFileFormatTest extends TestCase
{
    public function testEveryCaseDeclaresAtLeastOneExtensionAndOneMimeType(): void
    {
        foreach (AllowedFileFormat::cases() as $format) {
            self::assertNotEmpty($format->extensions(), $format->value . ' has no extensions');
            self::assertNotEmpty($format->mimeTypes(), $format->value . ' has no MIME types');
        }
    }

    public function testExtensionsAreLowercaseWithoutALeadingDot(): void
    {
        foreach (AllowedFileFormat::cases() as $format) {
            foreach ($format->extensions() as $extension) {
                self::assertSame(strtolower($extension), $extension, $extension . ' is not lowercase');
                self::assertStringStartsNotWith('.', $extension);
            }
        }
    }

    /** No two formats should claim the same extension — a picker offering both would be ambiguous about which one a file belongs to. */
    public function testNoExtensionIsSharedByTwoFormats(): void
    {
        $seenBy = [];
        foreach (AllowedFileFormat::cases() as $format) {
            foreach ($format->extensions() as $extension) {
                self::assertArrayNotHasKey($extension, $seenBy, sprintf(
                    '"%s" is claimed by both %s and %s',
                    $extension,
                    $seenBy[$extension] ?? '',
                    $format->value,
                ));
                $seenBy[$extension] = $format->value;
            }
        }
    }

    public function testEveryCaseHasADistinctLabelKey(): void
    {
        $keys = array_map(static fn (AllowedFileFormat $f): string => $f->labelKey(), AllowedFileFormat::cases());

        self::assertCount(count($keys), array_unique($keys));
    }
}
