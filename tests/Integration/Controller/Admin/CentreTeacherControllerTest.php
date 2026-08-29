<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;

final class CentreTeacherControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** @return array{0: EducationalCentre, 1: AcademicYear, 2: Teacher} */
    private function centreWithAdminAndActiveYear(): array
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);

        return [$centre, $year, $admin];
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

    public function testAddExistingTeacherToTheActiveYear(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $existing = $this->teacher('existente');
        $this->persist($centre, $year, $admin, $existing);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/docentes-curso");
        $this->client->request('POST', "/centro/{$centreId}/docentes-curso/añadir", [
            '_token'   => $this->csrfToken('add_centre_teacher'),
            'username' => 'existente',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/docentes-curso"));

        $this->em->clear();
        /** @var \App\Repository\AcademicYearRepository $years */
        $years         = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloadedYear  = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloadedYear);
        /** @var TeacherRepository $teachers */
        $teachers        = self::getContainer()->get(TeacherRepository::class);
        $reloadedTeacher = $teachers->findById($existing->getId()->toRfc4122());
        self::assertNotNull($reloadedTeacher);
        self::assertTrue($reloadedYear->getTeachers()->contains($reloadedTeacher));
    }

    public function testAddOfAnUnknownUsernameRedirectsToRegister(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/docentes-curso");
        $this->client->request('POST', "/centro/{$centreId}/docentes-curso/añadir", [
            '_token'   => $this->csrfToken('add_centre_teacher'),
            'username' => 'no_existe',
        ]);

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/docentes-curso/registrar', $location);
    }

    public function testRegisterCreatesAndAddsANewInternalTeacher(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/docentes-curso/registrar");
        $this->client->request('POST', "/centro/{$centreId}/docentes-curso/registrar", [
            '_token'      => $this->csrfToken('register_centre_teacher'),
            'first_name'  => 'Nuevo',
            'last_name'   => 'Docente',
            'username'    => 'nuevo_docente',
            'email'       => '',
            'password'    => 'contraseña-larga-123',
            'active'      => '1',
            'auth_method' => 'local',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/docentes-curso"));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $created  = $teachers->findByUsername('nuevo_docente');
        self::assertNotNull($created);
        self::assertFalse($created->isAdmin(), 'registering a teacher from the centre screen must never grant global admin');
        /** @var \App\Repository\AcademicYearRepository $years */
        $years        = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloadedYear = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloadedYear);
        self::assertTrue($reloadedYear->getTeachers()->contains($created));
    }

    public function testRegisterRejectsADuplicateUsername(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $existing = $this->teacher('ya_existe');
        $this->persist($centre, $year, $admin, $existing);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/docentes-curso/registrar");
        $this->client->request('POST', "/centro/{$centreId}/docentes-curso/registrar", [
            '_token'      => $this->csrfToken('register_centre_teacher'),
            'first_name'  => 'Nombre',
            'last_name'   => 'Apellido',
            'username'    => 'ya_existe',
            'email'       => '',
            'password'    => 'contraseña-larga-123',
            'active'      => '1',
            'auth_method' => 'local',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testRemoveTakesTheTeacherOutOfTheActiveYear(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $member = $this->teacher('miembro');
        $year->getTeachers()->add($member);
        $this->persist($centre, $year, $admin, $member);
        $centreId  = $centre->getId()->toRfc4122();
        $memberId  = $member->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/docentes-curso");
        $this->client->request('POST', "/centro/{$centreId}/docentes-curso/{$memberId}/quitar", [
            '_token' => $this->csrfToken('remove_centre_teacher_' . $memberId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/docentes-curso"));

        $this->em->clear();
        /** @var \App\Repository\AcademicYearRepository $years */
        $years        = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloadedYear = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloadedYear);
        /** @var TeacherRepository $teachers */
        $teachers        = self::getContainer()->get(TeacherRepository::class);
        $reloadedMember  = $teachers->findById($memberId);
        self::assertNotNull($reloadedMember);
        self::assertFalse($reloadedYear->getTeachers()->contains($reloadedMember));
        // The teacher entity itself is not deleted, just detached from this year.
        self::assertNotNull($teachers->findById($memberId));
    }

    /** Write operations must be blocked while browsing a non-active (past) academic year. */
    public function testAddDeniedWhileViewingAPastYear(): void
    {
        [$centre, $activeYear, $admin] = $this->centreWithAdminAndActiveYear();
        $pastYear = (new AcademicYear())->setName('2023-2024')->setEducationalCentre($centre);
        $existing = $this->teacher('existente');
        $this->persist($centre, $activeYear, $admin, $pastYear, $existing);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);
        $this->client->request('POST', "/centro/{$centreId}/docentes-curso/añadir", [
            '_token'   => $this->csrfToken('add_centre_teacher'),
            'username' => 'existente',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
