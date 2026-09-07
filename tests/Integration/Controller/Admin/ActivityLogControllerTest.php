<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class ActivityLogControllerTest extends ControllerTestCase
{
    private function teacher(string $username, bool $admin = false): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
        $teacher->setAdmin($admin);

        return $teacher;
    }

    public function testDeniedForANonAdmin(): void
    {
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $this->loginAs($teacher);
        $this->client->request('GET', '/admin/registro-actividad');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRendersForAGlobalAdmin(): void
    {
        $admin = $this->teacher('root', admin: true);
        $this->persist($admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/registro-actividad');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Registro de actividad', (string) $this->client->getResponse()->getContent());
    }
}
