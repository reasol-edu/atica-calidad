<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\AcademicYearRepository;
use App\Tests\Integration\ControllerTestCase;

final class AcademicYearControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    public function testIndexDeniedWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAddCreatesANewYear(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos");
        $this->client->request('POST', "/centro/{$centreId}/cursos/nuevo", [
            '_token' => $this->csrfToken('add_year_' . $centreId),
            'name'   => '2026-2027',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/cursos"));

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years    = self::getContainer()->get(AcademicYearRepository::class);
        $reloaded = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class)->findById($centreId);
        self::assertNotNull($reloaded);
        $names = array_map(static fn (AcademicYear $y): string => $y->getName(), $years->findByCentreOrderedByName($reloaded));
        self::assertContains('2026-2027', $names);
    }

    public function testAddRejectsAnEmptyName(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos");
        $this->client->request('POST', "/centro/{$centreId}/cursos/nuevo", [
            '_token' => $this->csrfToken('add_year_' . $centreId),
            'name'   => '   ',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/cursos"));

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years    = self::getContainer()->get(AcademicYearRepository::class);
        $reloaded = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class)->findById($centreId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $years->findByCentreOrderedByName($reloaded));
    }

    public function testEditRenamesTheYear(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $year->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos/{$yearId}/editar");
        $this->client->request('POST', "/centro/{$centreId}/cursos/{$yearId}/editar", [
            '_token' => $this->csrfToken('edit_year_' . $yearId),
            'name'   => '2025-2026 (renombrado)',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/cursos"));

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years    = self::getContainer()->get(AcademicYearRepository::class);
        $reloaded = $years->findById($yearId);
        self::assertNotNull($reloaded);
        self::assertSame('2025-2026 (renombrado)', $reloaded->getName());
    }

    public function testDeleteRemovesANonActiveYear(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $pastYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $activeYear, $pastYear, $admin);
        $centreId   = $centre->getId()->toRfc4122();
        $pastYearId = $pastYear->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos");
        $this->client->request('POST', "/centro/{$centreId}/cursos/{$pastYearId}/eliminar", [
            '_token' => $this->csrfToken('delete_year_' . $pastYearId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/cursos"));

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years = self::getContainer()->get(AcademicYearRepository::class);
        self::assertNull($years->findById($pastYearId));
    }

    public function testDeleteBlockedForTheActiveYear(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $activeYear, $admin);
        $centreId = $centre->getId()->toRfc4122();
        $yearId   = $activeYear->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos");
        $this->client->request('POST', "/centro/{$centreId}/cursos/{$yearId}/eliminar", [
            '_token' => $this->csrfToken('delete_year_' . $yearId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/cursos"));

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years = self::getContainer()->get(AcademicYearRepository::class);
        self::assertNotNull($years->findById($yearId), 'the active year must never be deletable');
    }

    public function testActivateChangesTheCentresActiveYear(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $otherYear  = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $activeYear, $otherYear, $admin);
        $centreId    = $centre->getId()->toRfc4122();
        $otherYearId = $otherYear->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/cursos");
        $this->client->request('POST', "/centro/{$centreId}/cursos/{$otherYearId}/activar", [
            '_token' => $this->csrfToken('activate_year_' . $otherYearId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/cursos"));

        $this->em->clear();
        /** @var \App\Repository\EducationalCentreRepository $centres */
        $centres  = self::getContainer()->get(\App\Repository\EducationalCentreRepository::class);
        $reloaded = $centres->findByIdWithActiveYear($centreId);
        self::assertNotNull($reloaded);
        $reloadedActive = $reloaded->getActiveAcademicYear();
        self::assertNotNull($reloadedActive);
        self::assertSame($otherYearId, $reloadedActive->getId()->toRfc4122());
    }
}
