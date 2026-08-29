<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class DashboardControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testRendersForAnAuthenticatedTeacherWithASelectedCentre(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testRedirectsToLoginForAnAnonymousVisitor(): void
    {
        $this->client->request('GET', '/');

        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    /**
     * TenantContextSubscriber auto-selects the centre when exactly one is accessible, so this
     * only surfaces the "please choose" redirect when a teacher can reach more than one — give
     * them two, matching a global admin's normal situation.
     */
    public function testRedirectsToCentreSelectionWhenMoreThanOneCentreIsAccessible(): void
    {
        $centreA = $this->centre();
        $centreB = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Otra ciudad');
        $admin   = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centreA, $centreB, $admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/');

        self::assertTrue($this->client->getResponse()->isRedirect('/seleccion/centro'));
    }

    public function testAutoSelectsTheOnlyAccessibleCentre(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $centre->getAdmins()->add($teacher);
        $this->persist($centre, $teacher);

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'a teacher with exactly one accessible centre must be auto-selected into it, not asked to choose');
    }
}
