<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reglas mínimas que debe cumplir una contraseña al crearse o cambiarse.
 * Devuelve null si es válida, o una clave de traducción del dominio `messages`
 * si no lo es. Centralizar aquí permite que perfil y reset apliquen la misma
 * política sin duplicar reglas.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public function firstViolationKey(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'profile.error.password_too_short';
        }

        return null;
    }
}
