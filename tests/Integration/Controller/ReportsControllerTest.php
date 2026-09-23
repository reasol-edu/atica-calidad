<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class ReportsControllerTest extends ControllerTestCase
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
        $this->client->request('GET', "/centro/{$centreId}/informes");

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
        $this->client->request('GET', "/centro/{$centreId}/informes");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testIndexReturns404ForAnUnknownCentre(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/centro/01945c2e-0000-7000-8000-000000000000/informes');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** @return array{EducationalCentre, string} a centre with one document, and its id */
    private function centreWithADocument(Teacher $viewer, string $role): array
    {
        $centre  = $this->centre();
        match ($role) {
            'quality' => $centre->getQualityManagers()->add($viewer),
            'auditor' => $centre->getInternalAuditors()->add($viewer),
            default   => $centre->getAdmins()->add($viewer),
        };
        $section  = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Procedimientos');
        $folder   = (new \App\Entity\Folder())->setDocumentSection($section)->setName('Calidad');
        $document = new \App\Entity\Document($folder, 'Manual de calidad');
        $file     = new \App\Entity\DocumentFile(hash('sha256', 'manual'), 'x', 'application/pdf', 'm.pdf', 1);
        $revision = new \App\Entity\DocumentRevision($document, 1, $file, false, $viewer);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($centre, $viewer, $section, $folder, $file, $document, $revision);

        return [$centre, $centre->getId()->toRfc4122()];
    }

    /** @return iterable<string, array{string, string}> */
    public static function reportReaderProvider(): iterable
    {
        yield 'quality manager'  => ['quality', 'calidad'];
        yield 'internal auditor' => ['auditor', 'auditora'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportReaderProvider')]
    public function testQualityManagersAndInternalAuditorsReachTheReports(string $role, string $username): void
    {
        $teacher = $this->teacher($username);
        [$centre, $centreId] = $this->centreWithADocument($teacher, $role);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/informes");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Listado maestro de documentos', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('1 documento.', (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function reportPathProvider(): iterable
    {
        yield 'master list'      => ['listado-maestro'];
        yield 'document reviews' => ['revisiones-de-documentos'];
        yield 'activity status'  => ['estado-de-actividades'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportPathProvider')]
    public function testEachReportDownloadsAsPdfAndExcel(string $path): void
    {
        $admin = $this->teacher('director');
        [$centre, $centreId] = $this->centreWithADocument($admin, 'admin');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', "/centro/{$centreId}/informes/{$path}.pdf");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', "/centro/{$centreId}/informes/{$path}.xlsx");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAPlainTeacherCannotDownloadAReport(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/informes/listado-maestro.xlsx");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
