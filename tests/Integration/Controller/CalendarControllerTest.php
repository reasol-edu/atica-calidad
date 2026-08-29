<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class CalendarControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testIndexRendersForAnyAuthenticatedTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testEventsTabIsIgnoredForANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario?tab=events');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testDayViewRendersForAValidDate(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario/dia/2025-09-08');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testDayViewRejectsAMalformedDate(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario/dia/not-a-date');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDayViewShowsAnEventOnItsDate(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-08'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Claustro')
            ->setGeneral(true);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $event, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/calendario/dia/2025-09-08');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Claustro', $body);
    }
}
