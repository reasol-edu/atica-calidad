<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\Uuid;

/**
 * Symfony's AbstractUidType never overrides getBindingType(), so every UUID parameter is bound
 * as ParameterType::STRING — even on platforms where the column is actually BINARY(16) (MySQL,
 * SQLite; PostgreSQL has a native UUID type and is unaffected). For pdo_sqlite specifically,
 * PDO::PARAM_STR treats the 16-byte value as a NUL-terminated C string: any UUID whose binary
 * form happens to contain an embedded 0x00 byte gets silently truncated on write, corrupting the
 * primary key. PDO::PARAM_LOB binds the exact byte length instead and isn't affected.
 *
 * getBindingType() takes no $platform argument — Type instances are shared across every
 * connection in the process, but a running app only ever talks to the one platform it's
 * configured for, so caching which branch applies (set from the platform-aware methods that DO
 * run on every write/schema build) is safe and avoids a bigger change like duplicating
 * getSQLDeclaration()/convertToDatabaseValue() just to inline the platform check.
 */
final class UuidType extends AbstractUidType
{
    public const NAME = 'uuid';

    private ?bool $isBinaryOnCurrentPlatform = null;

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $this->isBinaryOnCurrentPlatform = !$this->hasNativeGuidType($platform);

        return parent::getSQLDeclaration($column, $platform);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        $this->isBinaryOnCurrentPlatform = !$this->hasNativeGuidType($platform);

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function getBindingType(): ParameterType
    {
        return $this->isBinaryOnCurrentPlatform === false ? ParameterType::STRING : ParameterType::BINARY;
    }

    private function hasNativeGuidType(AbstractPlatform $platform): bool
    {
        return $platform->getGuidTypeDeclarationSQL([]) !== $platform->getStringTypeDeclarationSQL(['fixed' => true, 'length' => 36]);
    }
}
