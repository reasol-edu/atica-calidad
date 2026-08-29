<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use PHPUnit\Framework\TestCase;

final class AcademicYearTest extends TestCase
{
    private function year(): AcademicYear
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');

        return (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
    }

    private function teacher(): Teacher
    {
        return (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia');
    }

    public function testAddTeacherSyncsTheInverseSide(): void
    {
        $year    = $this->year();
        $teacher = $this->teacher();

        $year->addTeacher($teacher);

        self::assertTrue($year->getTeachers()->contains($teacher));
        self::assertTrue($teacher->getAcademicYears()->contains($year));
    }

    public function testAddTeacherIsIdempotent(): void
    {
        $year    = $this->year();
        $teacher = $this->teacher();

        $year->addTeacher($teacher);
        $year->addTeacher($teacher);

        self::assertCount(1, $year->getTeachers());
    }

    public function testRemoveTeacherSyncsTheInverseSide(): void
    {
        $year    = $this->year();
        $teacher = $this->teacher();
        $year->addTeacher($teacher);

        $year->removeTeacher($teacher);

        self::assertFalse($year->getTeachers()->contains($teacher));
        self::assertFalse($teacher->getAcademicYears()->contains($year));
    }
}
