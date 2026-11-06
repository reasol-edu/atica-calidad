<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine\Type;

use App\Doctrine\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;

/** Las fechas con hora se guardan en UTC y se leen en la zona horaria de la aplicación. */
class UtcDateTimeImmutableTypeTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        $this->previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previous);
    }

    public function testALocalTimeIsStoredAsUtc(): void
    {
        $type = new UtcDateTimeImmutableType();

        // En verano Madrid va dos horas por delante de UTC; en invierno, una.
        self::assertSame('2026-07-01 08:30:00', $type->convertToDatabaseValue(new \DateTimeImmutable('2026-07-01 10:30:00'), new SQLitePlatform()));
        self::assertSame('2026-12-01 09:30:00', $type->convertToDatabaseValue(new \DateTimeImmutable('2026-12-01 10:30:00'), new SQLitePlatform()));
    }

    public function testAStoredUtcTimeIsReadInTheApplicationTimezone(): void
    {
        $value = (new UtcDateTimeImmutableType())->convertToPHPValue('2026-07-01 08:30:00', new SQLitePlatform());

        self::assertNotNull($value);
        self::assertSame('Europe/Madrid', $value->getTimezone()->getName());
        self::assertSame('2026-07-01 10:30:00', $value->format('Y-m-d H:i:s'));
    }

    public function testTheSameMomentRoundTripsWhateverTheZoneItWasBuiltIn(): void
    {
        $type   = new UtcDateTimeImmutableType();
        $utc    = new \DateTimeImmutable('2026-03-29 00:30:00', new \DateTimeZone('UTC'));
        $stored = $type->convertToDatabaseValue($utc, new SQLitePlatform());

        self::assertSame('2026-03-29 00:30:00', $stored);
        self::assertSame($utc->getTimestamp(), $type->convertToPHPValue($stored, new SQLitePlatform())?->getTimestamp());
    }

    public function testNullStaysNull(): void
    {
        $type = new UtcDateTimeImmutableType();

        self::assertNull($type->convertToDatabaseValue(null, new SQLitePlatform()));
        self::assertNull($type->convertToPHPValue(null, new SQLitePlatform()));
    }
}
