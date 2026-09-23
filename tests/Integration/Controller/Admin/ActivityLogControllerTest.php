<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
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

    public function testExportIsDeniedForANonAdmin(): void
    {
        $teacher = $this->teacher('docente');
        $this->persist($teacher);

        $this->loginAs($teacher);
        $this->client->request('GET', '/admin/registro-actividad/exportar.csv');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCsvExportAppliesTheFilters(): void
    {
        $admin  = $this->teacher('root', admin: true);
        $victim = $this->teacher('=cmd');
        $this->persist(
            $admin,
            $victim,
            new ActivityLog(new \DateTimeImmutable('2026-01-10 10:00:00'), '10.0.0.1', 'session.login', activeUser: $victim),
            new ActivityLog(new \DateTimeImmutable('2026-01-11 10:00:00'), '10.0.0.2', 'session.logout', activeUser: $victim),
            new ActivityLog(new \DateTimeImmutable('2025-06-01 10:00:00'), '10.0.0.3', 'session.login', activeUser: $victim),
        );

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/registro-actividad/exportar.csv?actionType=session.login&dateFrom=2026-01-01T00:00&centreId=nope');

        $response = $this->client->getInternalResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', (string) $response->getHeader('Content-Type'));
        self::assertStringContainsString('attachment; filename=registro-actividad-', (string) $response->getHeader('Content-Disposition'));

        $lines = explode("\n", trim(substr($response->getContent(), 3)));
        self::assertSame('Fecha;IP;Usuario;Centro;Acción;Detalle', $lines[0]);
        self::assertCount(2, $lines, 'only the 2026 login matches');
        // The user cell starts with the last name "=cmd": quoted so no spreadsheet runs it.
        self::assertSame(
            ['10/01/2026 10:00:00', '10.0.0.1', "'=cmd, Nombre (=cmd)", '', 'Inicio de sesión', ''],
            str_getcsv($lines[1], ';', escape: ''),
        );
    }

    public function testPdfExport(): void
    {
        $admin = $this->teacher('root', admin: true);
        $this->persist(
            $admin,
            new ActivityLog(new \DateTimeImmutable('2026-01-10 10:00:00'), '10.0.0.1', 'session.login', activeUser: $admin),
        );

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/registro-actividad/exportar.pdf?userQuery=root');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function testListOffersTheExportWithTheCurrentFilters(): void
    {
        $admin = $this->teacher('root', admin: true);
        $this->persist($admin);

        $this->loginAs($admin);
        $crawler = $this->client->request('GET', '/admin/registro-actividad');

        self::assertCount(1, $crawler->filter('a[href*="/admin/registro-actividad/exportar.csv"]'));
        self::assertCount(1, $crawler->filter('a[href*="/admin/registro-actividad/exportar.pdf"]'));
    }
}
