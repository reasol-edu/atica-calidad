<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;

final class TeacherControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function admin(string $username = 'admin'): Teacher
    {
        $teacher = $this->teacher($username);
        $teacher->setAdmin(true);

        return $teacher;
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

    public function testIndexDeniedToANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/admin/docentes');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testNewCreatesALocalTeacher(): void
    {
        $admin = $this->admin();
        $this->persist($admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/docentes/nuevo');
        $this->client->request('POST', '/admin/docentes/nuevo', [
            '_token'      => $this->csrfToken('new_teacher'),
            'first_name'  => 'Nuevo',
            'last_name'   => 'Docente',
            'username'    => 'nuevo_docente',
            'email'       => '',
            'password'    => 'contraseña-larga-123',
            'active'      => 'yes',
            'auth_method' => 'local',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/docentes'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $created  = $teachers->findByUsername('nuevo_docente');
        self::assertNotNull($created);
        self::assertTrue($created->isActive());
        self::assertFalse($created->isAdmin());
    }

    public function testNewRejectsADuplicateUsername(): void
    {
        $existing = $this->teacher('ya_existe');
        $admin    = $this->admin();
        $this->persist($existing, $admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/docentes/nuevo');
        $this->client->request('POST', '/admin/docentes/nuevo', [
            '_token'      => $this->csrfToken('new_teacher'),
            'first_name'  => 'Nombre',
            'last_name'   => 'Apellido',
            'username'    => 'ya_existe',
            'email'       => '',
            'password'    => 'contraseña-larga-123',
            'active'      => 'yes',
            'auth_method' => 'local',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testNewRejectsAMissingPasswordForALocalAccount(): void
    {
        $admin = $this->admin();
        $this->persist($admin);

        $this->loginAs($admin);
        $this->client->request('GET', '/admin/docentes/nuevo');
        $this->client->request('POST', '/admin/docentes/nuevo', [
            '_token'      => $this->csrfToken('new_teacher'),
            'first_name'  => 'Nombre',
            'last_name'   => 'Apellido',
            'username'    => 'sin_contrasena',
            'email'       => '',
            'password'    => '',
            'active'      => 'yes',
            'auth_method' => 'local',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        self::assertNull($teachers->findByUsername('sin_contrasena'));
    }

    public function testEditUpdatesFields(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $admin   = $this->admin();
        $this->persist($centre, $teacher, $admin);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('GET', "/admin/docentes/{$teacherId}");
        $this->client->request('POST', "/admin/docentes/{$teacherId}", [
            '_token'      => $this->csrfToken('edit_teacher_' . $teacherId),
            'first_name'  => 'Actualizado',
            'last_name'   => 'Docente',
            'username'    => 'docente',
            'email'       => '',
            'password'    => '',
            'active'      => 'yes',
            'auth_method' => 'local',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/admin/docentes/{$teacherId}"));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertSame('Actualizado', $reloaded->getName()->getFirstName());
    }

    /** An admin editing their own account can never demote themselves — self-lockout protection. */
    public function testEditPreventsAnAdminFromDemotingThemselves(): void
    {
        $admin = $this->admin();
        $this->persist($admin);
        $adminId = $admin->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('GET', "/admin/docentes/{$adminId}");
        $this->client->request('POST', "/admin/docentes/{$adminId}", [
            '_token'      => $this->csrfToken('edit_teacher_' . $adminId),
            'first_name'  => 'Admin',
            'last_name'   => 'Uno',
            'username'    => 'admin',
            'email'       => '',
            'password'    => '',
            'active'      => 'yes',
            'auth_method' => 'local',
            // 'admin' field deliberately omitted ⇒ interpreted as "no" ⇒ self-demotion attempt.
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/admin/docentes/{$adminId}"));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($adminId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isAdmin(), 'an admin must never be able to remove their own admin role');
    }

    public function testEditPreventsAnAdminFromDeactivatingThemselves(): void
    {
        $admin = $this->admin();
        $this->persist($admin);
        $adminId = $admin->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('GET', "/admin/docentes/{$adminId}");
        $this->client->request('POST', "/admin/docentes/{$adminId}", [
            '_token'      => $this->csrfToken('edit_teacher_' . $adminId),
            'first_name'  => 'Admin',
            'last_name'   => 'Uno',
            'username'    => 'admin',
            'email'       => '',
            'password'    => '',
            'admin'       => 'yes',
            'auth_method' => 'local',
            // 'active' field deliberately omitted ⇒ deactivation attempt.
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/admin/docentes/{$adminId}"));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($adminId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive(), 'an admin must never be able to deactivate their own account');
    }

    public function testDeleteRemovesATeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $admin   = $this->admin();
        $this->persist($centre, $teacher, $admin);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('POST', "/admin/docentes/{$teacherId}/eliminar", [
            '_token' => $this->csrfToken('delete_teacher_' . $teacherId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/docentes'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        self::assertNull($teachers->findById($teacherId));
    }

    public function testDeletePreventsAnAdminFromDeletingThemselves(): void
    {
        $admin = $this->admin();
        $this->persist($admin);
        $adminId = $admin->getId()->toRfc4122();

        $this->loginAs($admin);
        $this->client->request('POST', "/admin/docentes/{$adminId}/eliminar", [
            '_token' => $this->csrfToken('delete_teacher_' . $adminId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/admin/docentes'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        self::assertNotNull($teachers->findById($adminId), 'self-deletion must be blocked');
    }
}
