<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * `datetime_immutable` que guarda siempre en UTC y devuelve las fechas en la zona horaria de la aplicación
 * (`APP_TIMEZONE`, ver {@see \App\Kernel}). Las columnas de fecha y hora no llevan zona: sin esto se guardaría la hora
 * local tal cual y cambiar la zona de la aplicación movería todo lo ya guardado.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidFormat::new(get_debug_type($value), static::class, $platform->getDateTimeFormatString(), null);
        }

        $converted = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value, self::utc());
        if ($converted === false) {
            // Formatos con fracciones de segundo o sin hora: lo que acepte PHP, también como UTC.
            try {
                $converted = new \DateTimeImmutable($value, self::utc());
            } catch (\Exception $e) {
                throw InvalidFormat::new($value, static::class, $platform->getDateTimeFormatString(), $e);
            }
        }

        return $converted->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
