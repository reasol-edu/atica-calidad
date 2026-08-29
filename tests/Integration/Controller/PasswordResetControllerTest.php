<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetControllerTest extends ControllerTestCase
{
    use MailerAssertionsTrait;
    use ClockSensitiveTrait;

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

    private function csrfTokenBeforeAnyRequest(string $id): string
    {
        // No request/session exists yet at all — materialise one with a throwaway GET first.
        $this->client->request('GET', '/contrasena/recuperar');

        return $this->csrfToken($id);
    }

    public function testRequestPageRendersForAnAnonymousVisitor(): void
    {
        $this->client->request('GET', '/contrasena/recuperar');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testRequestPageRedirectsAnAuthenticatedTeacher(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/contrasena/recuperar');

        self::assertTrue($this->client->getResponse()->isRedirect('/'));
    }

    public function testRequestForAnExistingInternalTeacherWithEmailSendsTheResetEmail(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $teacher->setEmail('docente@example.com');
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $token = $this->csrfTokenBeforeAnyRequest('password_reset_request');
        $this->client->request('POST', '/contrasena/recuperar', [
            '_csrf_token' => $token,
            'username'    => 'docente',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertEmailCount(1);

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getPasswordResetToken(), 'a reset token must be stored (hashed) for a valid request');
    }

    public function testRequestForAnUnknownUsernameSendsNoEmailButStillRedirects(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $token = $this->csrfTokenBeforeAnyRequest('password_reset_request');
        $this->client->request('POST', '/contrasena/recuperar', [
            '_csrf_token' => $token,
            'username'    => 'no_existe',
        ]);

        // Same redirect regardless of whether the user exists — no user-enumeration signal.
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertEmailCount(0);
    }

    public function testRequestForAnExternalTeacherSendsNoEmail(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente_externo');
        $teacher->setEmail('externo@example.com')->setExternal(true);
        $this->persist($centre, $teacher);

        $token = $this->csrfTokenBeforeAnyRequest('password_reset_request');
        $this->client->request('POST', '/contrasena/recuperar', [
            '_csrf_token' => $token,
            'username'    => 'docente_externo',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertEmailCount(0, null, 'external accounts authenticate against iSéneca — a local password reset makes no sense for them');
    }

    public function testRequestRejectedWithInvalidCsrfToken(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $this->client->request('POST', '/contrasena/recuperar', [
            '_csrf_token' => 'invalido',
            'username'    => 'docente',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertEmailCount(0);
    }

    public function testResetWithAnUnknownTokenShowsAnError(): void
    {
        $this->client->request('GET', '/contrasena/restablecer/token-inventado');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testResetWithAnExpiredTokenShowsAnErrorAndClearsIt(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $plainToken = 'un-token-de-prueba';
        $teacher->setPasswordResetToken($plainToken);
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        self::mockTime('2024-01-01 10:00:00');
        $teacher->setPasswordResetTokenExpiresAt(\Symfony\Component\Clock\now()->modify('-1 hour'));
        $this->flush();

        $this->client->request('GET', "/contrasena/restablecer/{$plainToken}");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getPasswordResetToken(), 'an expired token must be cleared on use');
    }

    public function testResetWithAValidTokenChangesThePassword(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $plainToken = 'un-token-valido';
        $teacher->setPasswordResetToken($plainToken)
            ->setPasswordResetTokenExpiresAt(\Symfony\Component\Clock\now()->modify('+1 hour'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $token = $this->csrfTokenBeforeAnyRequestAt("/contrasena/restablecer/{$plainToken}", 'password_reset');
        $this->client->request('POST', "/contrasena/restablecer/{$plainToken}", [
            '_csrf_token'      => $token,
            'password'         => 'nueva-contraseña-larga-456',
            'password_confirm' => 'nueva-contraseña-larga-456',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/login?password_reset=success'));

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getPasswordResetToken());
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($reloaded, 'nueva-contraseña-larga-456'));
    }

    public function testResetRejectsAPasswordShorterThanThePolicyMinimum(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $plainToken = 'un-token-valido-corto';
        $teacher->setPasswordResetToken($plainToken)
            ->setPasswordResetTokenExpiresAt(\Symfony\Component\Clock\now()->modify('+1 hour'));
        $this->persist($centre, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();

        $token = $this->csrfTokenBeforeAnyRequestAt("/contrasena/restablecer/{$plainToken}", 'password_reset');
        $this->client->request('POST', "/contrasena/restablecer/{$plainToken}", [
            '_csrf_token'      => $token,
            'password'         => 'corta',
            'password_confirm' => 'corta',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $reloaded = $teachers->findById($teacherId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getPasswordResetToken(), 'the token must remain usable after a rejected attempt');
    }

    public function testResetRejectsMismatchedConfirmation(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $plainToken = 'un-token-valido-mismatch';
        $teacher->setPasswordResetToken($plainToken)
            ->setPasswordResetTokenExpiresAt(\Symfony\Component\Clock\now()->modify('+1 hour'));
        $this->persist($centre, $teacher);

        $token = $this->csrfTokenBeforeAnyRequestAt("/contrasena/restablecer/{$plainToken}", 'password_reset');
        $this->client->request('POST', "/contrasena/restablecer/{$plainToken}", [
            '_csrf_token'      => $token,
            'password'         => 'nueva-contraseña-larga-456',
            'password_confirm' => 'otra-distinta-789',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function csrfTokenBeforeAnyRequestAt(string $path, string $id): string
    {
        $this->client->request('GET', $path);

        return $this->csrfToken($id);
    }
}
