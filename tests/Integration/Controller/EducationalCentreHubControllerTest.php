<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class EducationalCentreHubControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testIndexDeniedForANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testIndexRendersForAnAdmin(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** Each card says how much is behind it, so the hub tells something before it is opened. */
    public function testIndexShowsHowMuchThereIsBehindEachCard(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $admin->addAcademicYear($year);
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}");
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('1 curso.', $html);
        self::assertStringContainsString('1 docente asignado.', $html);
        self::assertStringContainsString('Ninguno registrado este curso.', $html);
        self::assertStringContainsString('Ninguna respuesta todavía.', $html);
    }

    public function testWithoutAnActiveYearTheYearCardsShowNoCount(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}");
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Ningún curso todavía.', $html);
        self::assertStringNotContainsString('docente asignado', $html);
        self::assertStringNotContainsString('este curso.', $html);
    }

    public function testIndexReturns404ForAnUnknownCentre(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/centro/01945c2e-0000-7000-8000-000000000000');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSettingsDeniedForANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/ajustes");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSettingsRendersForAnAdmin(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/ajustes");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
