<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine\Type;

use App\Doctrine\Type\UuidType;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Regression test for the binding bug documented on UuidType: a UUID whose binary form contains
 * an embedded 0x00 byte must round-trip through a BINARY(16) SQLite column intact — with the
 * stock Symfony\Bridge\Doctrine\Types\UuidType this used to get silently truncated at the NUL
 * byte because it never overrides getBindingType(), so pdo_sqlite bound it as a C string
 * (ParameterType::STRING) instead of a byte-length-aware blob (ParameterType::BINARY).
 */
final class UuidTypeTest extends TestCase
{
    public function testRoundTripsAUuidWithAnEmbeddedNulByte(): void
    {
        if (!Type::hasType(UuidType::NAME)) {
            Type::addType(UuidType::NAME, UuidType::class);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE t (id BLOB NOT NULL)');

        $bytesWithEmbeddedNul = "\x01\x02\x00\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";
        $uuid                 = Uuid::fromBinary($bytesWithEmbeddedNul);

        $connection->createQueryBuilder()
            ->insert('t')
            ->setValue('id', ':id')
            ->setParameter('id', $uuid, UuidType::NAME)
            ->executeStatement();

        $stored = $connection->fetchOne('SELECT id FROM t');

        self::assertSame(16, strlen($stored));
        self::assertSame($bytesWithEmbeddedNul, $stored);
    }
}
