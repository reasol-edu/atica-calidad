<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\PrintableCalendar;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** The status line each card of the Responsabilidades and Utilidades hubs shows under its description. */
final class HubStatsTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testResponsibilitiesCardsCountTheirContents(): void
    {
        $centre  = $this->centre();
        $admin   = $this->teacher('director');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin, $profile);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades");
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ninguna lista todavía.', $html);
        self::assertStringContainsString('1 perfil.', $html);
    }

    public function testUtilitiesCountsTheOwnCalendarsOfTheYear(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $mine  = new PrintableCalendar($centre, $year, $teacher, 'FCT');
        $other = new PrintableCalendar($centre, $year, $this->teacher('otro'), 'De otro');
        $this->persist($centre, $year, $teacher, $other->getOwner(), $mine, $other);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/utilidades');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('1 guardado este curso.', $html);
    }
}
