<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class SettingsControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testDeniedToANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/admin/ajustes');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRendersForAnAdmin(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/admin/ajustes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
