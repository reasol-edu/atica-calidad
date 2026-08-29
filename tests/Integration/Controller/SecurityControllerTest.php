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
}
