<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends ControllerTestCase
{
    use MailerAssertionsTrait;

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

    public function testEditPageRendersForAnAuthenticatedTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdatingNameOnlySucceeds(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => $this->csrfToken('edit_profile'),
            'first_name' => 'Nuevo',
            'last_name'  => 'Apellido',
            'email'      => '',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertSame('Nuevo', $reloaded->getName()->getFirstName());
        self::assertSame('Apellido', $reloaded->getName()->getLastName());
    }

    public function testRejectsAnEmptyFirstName(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => $this->csrfToken('edit_profile'),
            'first_name' => '',
            'last_name'  => 'Apellido',
            'email'      => '',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertSame('Nombre', $reloaded->getName()->getFirstName(), 'the invalid submission must not have changed anything');
    }

    public function testRejectsAnInvalidEmailFormat(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => $this->csrfToken('edit_profile'),
            'first_name' => 'Nombre',
            'last_name'  => 'docente',
            'email'      => 'no-es-un-email',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsAnEmailAlreadyTakenByAnotherTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $other   = $this->teacher('otro');
        $other->setEmail('ocupado@example.com');
        $this->persist($centre, $teacher, $other);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => $this->csrfToken('edit_profile'),
            'first_name' => 'Nombre',
            'last_name'  => 'docente',
            'email'      => 'ocupado@example.com',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getEmail());
        self::assertNull($reloaded->getPendingEmail());
    }

    /** A plain teacher changing their email must go through verification — not applied immediately. */
    public function testChangingEmailAsAPlainTeacherSendsAVerificationEmailInsteadOfApplyingItDirectly(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => $this->csrfToken('edit_profile'),
            'first_name' => 'Nombre',
            'last_name'  => 'docente',
            'email'      => 'nuevo@example.com',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));
        self::assertEmailCount(1);

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getEmail(), 'the email must not change until the verification link is followed');
        self::assertSame('nuevo@example.com', $reloaded->getPendingEmail());
        self::assertNotNull($reloaded->getEmailVerificationToken());
    }

    /** An admin's own email change applies immediately, without the verification step. */
    public function testChangingEmailAsAnAdminAppliesImmediately(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centre, $admin);
        $adminId = $admin->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => $this->csrfToken('edit_profile'),
            'first_name' => 'Nombre',
            'last_name'  => 'admin',
            'email'      => 'admin@example.com',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));
        self::assertEmailCount(0);

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($adminId);
        self::assertNotNull($reloaded);
        self::assertSame('admin@example.com', $reloaded->getEmail());
    }

    public function testChangingPasswordSucceeds(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, 'contraseña-actual-123'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'                => $this->csrfToken('edit_profile'),
            'first_name'            => 'Nombre',
            'last_name'             => 'docente',
            'email'                 => '',
            'current_password'      => 'contraseña-actual-123',
            'new_password'          => 'nueva-contraseña-larga-456',
            'new_password_confirm'  => 'nueva-contraseña-larga-456',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/perfil'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'nueva-contraseña-larga-456'));
    }

    public function testChangingPasswordRejectsAWrongCurrentPassword(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, 'contraseña-actual-123'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'                => $this->csrfToken('edit_profile'),
            'first_name'            => 'Nombre',
            'last_name'             => 'docente',
            'email'                 => '',
            'current_password'      => 'contraseña-incorrecta',
            'new_password'          => 'nueva-contraseña-larga-456',
            'new_password_confirm'  => 'nueva-contraseña-larga-456',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'contraseña-actual-123'), 'the old password must remain valid');
    }

    public function testEditRejectedWithInvalidCsrfToken(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/perfil', [
            '_token'     => 'invalido',
            'first_name' => 'Nuevo',
            'last_name'  => 'Apellido',
            'email'      => '',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSettingsPageRenders(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/perfil/ajustes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
