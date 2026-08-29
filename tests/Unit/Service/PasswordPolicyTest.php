<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy();
    }

    public function testRejectsAPasswordShorterThanTheMinimumLength(): void
    {
        $tooShort = str_repeat('a', PasswordPolicy::MIN_LENGTH - 1);

        self::assertSame('profile.error.password_too_short', $this->policy->firstViolationKey($tooShort));
    }

    public function testAcceptsAPasswordAtExactlyTheMinimumLength(): void
    {
        $exact = str_repeat('a', PasswordPolicy::MIN_LENGTH);

        self::assertNull($this->policy->firstViolationKey($exact));
    }

    public function testAcceptsAPasswordLongerThanTheMinimum(): void
    {
        $long = str_repeat('a', PasswordPolicy::MIN_LENGTH + 20);

        self::assertNull($this->policy->firstViolationKey($long));
    }

    public function testCountsMultibyteCharactersByCharacterNotByte(): void
    {
        // Each 'ñ' is 2 bytes in UTF-8 — this must be judged by character count (12), not byte count (24).
        $password = str_repeat('ñ', PasswordPolicy::MIN_LENGTH);

        self::assertNull($this->policy->firstViolationKey($password));
    }

    public function testRejectsAnEmptyPassword(): void
    {
        self::assertSame('profile.error.password_too_short', $this->policy->firstViolationKey(''));
    }
}
