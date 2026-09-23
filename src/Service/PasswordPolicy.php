<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Minimum rules a password must satisfy when created or changed.
 * Returns null if valid, or a translation key from the `messages` domain
 * if not. Centralizing this here lets profile and reset apply the same
 * policy without duplicating rules.
 *
 * The rules, cheapest first: a minimum length; not containing the user's own username; and not
 * appearing in known data breaches (CompromisedPasswordChecker — skipped when not wired, as in
 * the plain unit tests, or when turned off).
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    /** A username shorter than this is too likely to appear in a password by chance. */
    private const int MIN_USERNAME_LENGTH_TO_CHECK = 3;

    public function __construct(
        private readonly ?CompromisedPasswordChecker $breaches = null,
    ) {}

    public function firstViolationKey(string $password, ?string $username = null): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'profile.error.password_too_short';
        }

        if ($username !== null
            && mb_strlen($username) >= self::MIN_USERNAME_LENGTH_TO_CHECK
            && str_contains(mb_strtolower($password), mb_strtolower($username))
        ) {
            return 'profile.error.password_contains_username';
        }

        if ($this->breaches?->isCompromised($password) === true) {
            return 'profile.error.password_compromised';
        }

        return null;
    }
}
