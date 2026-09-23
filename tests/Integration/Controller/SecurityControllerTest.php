<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class SecurityControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testLoginPageRendersForAnAnonymousVisitor(): void
    {
        $this->client->request('GET', '/login');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testLoginPageRedirectsAnAlreadyAuthenticatedTeacherToTheDashboard(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/login');

        self::assertTrue($this->client->getResponse()->isRedirect('/'));
    }

    public function testLoginRejectsInvalidCredentials(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $crawler = $this->client->request('GET', '/login');
        $form    = $crawler->filter('form')->first()->form();
        $form->setValues(['_username' => 'docente', '_password' => 'wrong-password']);
        $this->client->submit($form);

        self::assertTrue($this->client->getResponse()->isRedirect('/login'));

        // Not authenticated: a protected page must bounce back to the login form.
        $this->client->request('GET', '/perfil');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'), 'a failed login must not authenticate anyone');
    }

    /** @return string the login page's error message after submitting $username/$password */
    private function loginErrorFor(string $username, string $password): string
    {
        // login_throttling keeps its counters in a filesystem cache pool that outlives a test run:
        // start from zero so earlier runs' failed attempts can't turn this into "too many attempts".
        /** @var \Symfony\Contracts\Cache\CacheInterface&\Psr\Cache\CacheItemPoolInterface $limiterCache */
        $limiterCache = self::getContainer()->get('cache.rate_limiter');
        $limiterCache->clear();

        $crawler = $this->client->request('GET', '/login');
        $form    = $crawler->filter('form')->first()->form();
        $form->setValues(['_username' => $username, '_password' => $password]);
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));

        return $this->client->followRedirect()->filter('body')->text();
    }

    private function inactiveTeacherWithPassword(string $username, string $password): Teacher
    {
        $teacher = $this->teacher($username)->setActive(false);
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $teacher->setPassword($hasher->hashPassword($teacher, $password));

        return $teacher;
    }

    /** A wrong password must get the same generic answer whether or not the account is inactive — otherwise the login form tells anyone which usernames exist. */
    public function testAnInactiveAccountWithAWrongPasswordGetsTheGenericError(): void
    {
        $this->persist($this->centre(), $this->inactiveTeacherWithPassword('baja', 'contraseña-correcta'));

        $message = $this->loginErrorFor('baja', 'contraseña-incorrecta');

        self::assertStringContainsString('No hemos podido iniciar sesión con esas credenciales', $message);
        self::assertStringNotContainsString('desactivada', $message);
    }

    public function testAnInactiveAccountWithTheRightPasswordIsToldItIsDeactivated(): void
    {
        $this->persist($this->centre(), $this->inactiveTeacherWithPassword('baja', 'contraseña-correcta'));

        $message = $this->loginErrorFor('baja', 'contraseña-correcta');

        self::assertStringContainsString('Tu cuenta está desactivada', $message);

        $this->client->request('GET', '/perfil');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'), 'an inactive account must still not be authenticated');
    }
}
