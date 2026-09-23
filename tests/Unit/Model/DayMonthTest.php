<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\DayMonth;
use PHPUnit\Framework\TestCase;

final class DayMonthTest extends TestCase
{
    public function testParsesACanonicalValue(): void
    {
        $date = DayMonth::tryParse('09-15');

        self::assertNotNull($date);
        self::assertSame(9, $date->month);
        self::assertSame(15, $date->day);
        self::assertSame('09-15', $date->toString());
    }

    public function testRejectsDatesThatDoNotExistEveryYear(): void
    {
        self::assertNull(DayMonth::tryParse('02-29'));
        self::assertNull(DayMonth::tryParse('06-31'));
    }

    public function testRejectsMalformedAndNonStringValues(): void
    {
        self::assertNull(DayMonth::tryParse('9-15'));
        self::assertNull(DayMonth::tryParse('15/09'));
        self::assertNull(DayMonth::tryParse(' 09-15'));
        self::assertNull(DayMonth::tryParse(null));
        self::assertNull(DayMonth::tryParse(915));
    }
}
