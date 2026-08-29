<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;

final class ForcePasswordChangeControllerTest extends ControllerTestCase
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

    public function testRedirectsToDashboardWhenNoChangeIsRequired(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setForcePasswordChange(false);
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertTrue($this->client->getResponse()->isRedirect('/'));
    }

    public function testRedirectsToDashboardForAnExternalTeacherEvenIfFlagged(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente_externo');
        $teacher->setForcePasswordChange(true)->setExternal(true);
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertTrue($this->client->getResponse()->isRedirect('/'));
    }

    public function testRendersTheFormWhenAChangeIsRequired(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setForcePasswordChange(true);
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testSuccessfulChangeClearsTheFlagAndUpdatesThePassword(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setForcePasswordChange(true);
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, 'contraseña-actual-123'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            '_csrf_token'           => $this->csrfToken('force_password_change'),
            'current_password'      => 'contraseña-actual-123',
            'new_password'          => 'nueva-contraseña-larga-456',
            'new_password_confirm'  => 'nueva-contraseña-larga-456',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isForcePasswordChange());
        self::assertTrue($hasher->isPasswordValid($reloaded, 'nueva-contraseña-larga-456'));
    }

    public function testRejectsAnIncorrectCurrentPassword(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setForcePasswordChange(true);
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, 'contraseña-actual-123'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            '_csrf_token'           => $this->csrfToken('force_password_change'),
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
        self::assertTrue($reloaded->isForcePasswordChange(), 'the flag must stay set when the current password is wrong');
    }

    public function testRejectsAPasswordShorterThanThePolicyMinimum(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setForcePasswordChange(true);
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, 'contraseña-actual-123'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            '_csrf_token'           => $this->csrfToken('force_password_change'),
            'current_password'      => 'contraseña-actual-123',
            'new_password'          => 'corta',
            'new_password_confirm'  => 'corta',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isForcePasswordChange());
    }

    public function testRejectsMismatchedConfirmation(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setForcePasswordChange(true);
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, 'contraseña-actual-123'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', '/cambio-contrasena-obligatorio', [
            '_csrf_token'           => $this->csrfToken('force_password_change'),
            'current_password'      => 'contraseña-actual-123',
            'new_password'          => 'nueva-contraseña-larga-456',
            'new_password_confirm'  => 'otra-distinta-789',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isForcePasswordChange());
    }
}
