<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Minimum rules a password must satisfy when created or changed.
 * Returns null if valid, or a translation key from the `messages` domain
 * if not. Centralizing this here lets profile and reset apply the same
 * policy without duplicating rules.
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
