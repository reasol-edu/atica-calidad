<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TeacherTest extends TestCase
{
    use ClockSensitiveTrait;

    private function teacher(): Teacher
    {
        return (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia');
    }

    public function testGetRolesAlwaysIncludesRoleTeacher(): void
    {
        self::assertSame(['ROLE_TEACHER'], $this->teacher()->getRoles());
    }

    public function testGetRolesIncludesRoleAdminWhenAdmin(): void
    {
        $teacher = $this->teacher()->setAdmin(true);

        self::assertSame(['ROLE_TEACHER', 'ROLE_ADMIN'], $teacher->getRoles());
    }

    public function testGetUserIdentifierReturnsTheUsername(): void
    {
        self::assertSame('agarcia', $this->teacher()->getUserIdentifier());
    }

    public function testGetUserIdentifierThrowsWhenTheUsernameIsEmpty(): void
    {
        $teacher = (new Teacher(new PersonName('Ana', 'García')))->setUsername('');

        $this->expectException(\LogicException::class);
        $teacher->getUserIdentifier();
    }

    public function testHashTokenIsDeterministicAndDoesNotReturnTheRawToken(): void
    {
        $hash = Teacher::hashToken('un-token-secreto');

        self::assertSame($hash, Teacher::hashToken('un-token-secreto'));
        self::assertNotSame('un-token-secreto', $hash);
    }

    public function testSetEmailVerificationTokenStoresTheHashNotTheRawValue(): void
    {
        $teacher = $this->teacher();
        $teacher->setEmailVerificationToken('un-token');

        self::assertSame(Teacher::hashToken('un-token'), $teacher->getEmailVerificationToken());
    }

    public function testSetEmailVerificationTokenWithNullClearsIt(): void
    {
        $teacher = $this->teacher();
        $teacher->setEmailVerificationToken('un-token');
        $teacher->setEmailVerificationToken(null);

        self::assertNull($teacher->getEmailVerificationToken());
    }

    public function testSetPasswordResetTokenStoresTheHashNotTheRawValue(): void
    {
        $teacher = $this->teacher();
        $teacher->setPasswordResetToken('un-token');

        self::assertSame(Teacher::hashToken('un-token'), $teacher->getPasswordResetToken());
    }

    public function testEmailVerificationTokenWithNoExpiryIsConsideredExpired(): void
    {
        self::assertTrue($this->teacher()->isEmailVerificationTokenExpired());
    }

    public function testEmailVerificationTokenIsExpiredOncePastItsExpiryTime(): void
    {
        self::mockTime('2025-09-15 12:00:00');
        $teacher = $this->teacher();
        $teacher->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable('2025-09-15 11:59:59'));

        self::assertTrue($teacher->isEmailVerificationTokenExpired());
    }

    public function testEmailVerificationTokenIsNotExpiredBeforeItsExpiryTime(): void
    {
        self::mockTime('2025-09-15 12:00:00');
        $teacher = $this->teacher();
        $teacher->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable('2025-09-15 12:00:01'));

        self::assertFalse($teacher->isEmailVerificationTokenExpired());
    }

    public function testPasswordResetTokenWithNoExpiryIsConsideredExpired(): void
    {
        self::assertTrue($this->teacher()->isPasswordResetTokenExpired());
    }

    public function testPasswordResetTokenIsExpiredOncePastItsExpiryTime(): void
    {
        self::mockTime('2025-09-15 12:00:00');
        $teacher = $this->teacher();
        $teacher->setPasswordResetTokenExpiresAt(new \DateTimeImmutable('2025-09-15 11:59:59'));

        self::assertTrue($teacher->isPasswordResetTokenExpired());
    }

    public function testAddAcademicYearSyncsTheInverseSide(): void
    {
        $teacher = $this->teacher();
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);

        $teacher->addAcademicYear($year);

        self::assertTrue($teacher->getAcademicYears()->contains($year));
        self::assertTrue($year->getTeachers()->contains($teacher));
    }

    public function testAddAcademicYearIsIdempotent(): void
    {
        $teacher = $this->teacher();
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);

        $teacher->addAcademicYear($year);
        $teacher->addAcademicYear($year);

        self::assertCount(1, $teacher->getAcademicYears());
    }

    public function testRemoveAcademicYearSyncsTheInverseSide(): void
    {
        $teacher = $this->teacher();
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $teacher->addAcademicYear($year);

        $teacher->removeAcademicYear($year);

        self::assertFalse($teacher->getAcademicYears()->contains($year));
        self::assertFalse($year->getTeachers()->contains($teacher));
    }
}
