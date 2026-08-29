<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class CentreSelectionControllerTest extends ControllerTestCase
{
    private function centre(string $code = '12345678', string $name = 'Centro'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName($name)->setCity('Ciudad');
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

    public function testIndexListsOnlyCentresAccessibleToTheTeacher(): void
    {
        $centreA = $this->centre('11111111', 'Centro accesible');
        $centreB = $this->centre('22222222', 'Centro ajeno');
        $teacher = $this->teacher('docente');
        $centreA->getAdmins()->add($teacher);
        $this->persist($centreA, $centreB, $teacher);

        // Log in without pre-selecting a centre (loginAs's optional 2nd arg), to exercise this
        // page as it's actually reached — before any centre has been chosen.
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/seleccion/centro');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Centro accesible', $body);
        self::assertStringNotContainsString('Centro ajeno', $body);
    }

    public function testChooseSelectsAnAccessibleCentre(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $centre->getAdmins()->add($teacher);
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/seleccion/centro');
        $this->client->request('POST', "/seleccion/centro/{$centreId}", [
            '_token' => $this->csrfToken('select_centre_' . $centreId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect('/'));

        // The selection must have stuck: a subsequent request to a #[CurrentCentre] page succeeds.
        $this->client->request('GET', '/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testChooseDeniedForACentreTheTeacherCannotAccess(): void
    {
        $centreA = $this->centre('11111111', 'Accesible');
        $centreB = $this->centre('22222222', 'Ajeno');
        $teacher = $this->teacher('docente');
        $centreA->getAdmins()->add($teacher);
        $this->persist($centreA, $centreB, $teacher);
        $centreBId = $centreB->getId()->toRfc4122();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/seleccion/centro');
        $this->client->request('POST', "/seleccion/centro/{$centreBId}", [
            '_token' => $this->csrfToken('select_centre_' . $centreBId),
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testChooseRejectedWithInvalidCsrfToken(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $centre->getAdmins()->add($teacher);
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->client->loginUser($teacher);
        $this->client->request('POST', "/seleccion/centro/{$centreId}", [
            '_token' => 'invalido',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminSeesEveryCentreRegardlessOfMembership(): void
    {
        $centreA = $this->centre('11111111', 'Uno');
        $centreB = $this->centre('22222222', 'Dos');
        $admin   = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centreA, $centreB, $admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/seleccion/centro');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Uno', $body);
        self::assertStringContainsString('Dos', $body);
    }
}
