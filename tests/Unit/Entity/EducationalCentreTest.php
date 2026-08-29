<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use PHPUnit\Framework\TestCase;

final class EducationalCentreTest extends TestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(): Teacher
    {
        return (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia');
    }

    public function testRequireActiveAcademicYearThrowsWhenNoneIsSet(): void
    {
        $centre = $this->centre();

        $this->expectException(\LogicException::class);
        $centre->requireActiveAcademicYear();
    }

    public function testRequireActiveAcademicYearReturnsItWhenSet(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        self::assertSame($year, $centre->requireActiveAcademicYear());
    }

    public function testSetActiveAcademicYearRejectsAYearBelongingToAnotherCentre(): void
    {
        $centre       = $this->centre();
        $otherCentre  = $this->centre();
        $foreignYear  = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($otherCentre);

        $this->expectException(\LogicException::class);
        $centre->setActiveAcademicYear($foreignYear);
    }

    public function testAddAdminIsIdempotent(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();

        $centre->addAdmin($teacher);
        $centre->addAdmin($teacher);

        self::assertCount(1, $centre->getAdmins());
    }

    public function testRemoveAdminRemovesTheTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();
        $centre->addAdmin($teacher);

        $centre->removeAdmin($teacher);

        self::assertFalse($centre->getAdmins()->contains($teacher));
    }

    public function testAddQualityManagerIsIdempotent(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();

        $centre->addQualityManager($teacher);
        $centre->addQualityManager($teacher);

        self::assertCount(1, $centre->getQualityManagers());
    }

    public function testAddInternalAuditorIsIdempotent(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher();

        $centre->addInternalAuditor($teacher);
        $centre->addInternalAuditor($teacher);

        self::assertCount(1, $centre->getInternalAuditors());
    }
}
