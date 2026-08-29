<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class YearSelectionControllerTest extends ControllerTestCase
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

    public function testPageDeniedWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/curso/año');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testPageRendersForAnAdmin(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/curso/año');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testSelectingANonActiveYearStoresItInSession(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $pastYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->admin();
        $this->persist($centre, $activeYear, $pastYear, $admin);
        $pastYearId = $pastYear->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/curso/año');
        $this->client->request('POST', "/curso/año/{$pastYearId}", [
            '_token' => $this->csrfToken('select_year_' . $pastYearId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($this->client->getRequest());
        try {
            /** @var \App\Service\TenantContext $tenantContext */
            $tenantContext = self::getContainer()->get(\App\Service\TenantContext::class);
            $viewYear      = $tenantContext->getViewYear($centre);
        } finally {
            $requestStack->pop();
        }
        self::assertNotNull($viewYear);
        self::assertSame($pastYearId, $viewYear->getId()->toRfc4122());
    }

    public function testSelectingTheActiveYearResetsToNoOverride(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->admin();
        $this->persist($centre, $activeYear, $admin);
        $activeYearId = $activeYear->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/curso/año');
        $this->client->request('POST', "/curso/año/{$activeYearId}", [
            '_token' => $this->csrfToken('select_year_' . $activeYearId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
    }

    public function testSelectRejectedWithInvalidCsrfToken(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->admin();
        $this->persist($centre, $year, $admin);
        $yearId = $year->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/curso/año/{$yearId}", [
            '_token' => 'invalido',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSelectOfAnUnknownYearIs404(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/curso/año');
        $unknownId = '00000000-0000-0000-0000-000000000000';
        $this->client->request('POST', "/curso/año/{$unknownId}", [
            '_token' => $this->csrfToken('select_year_' . $unknownId),
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testResetClearsTheOverride(): void
    {
        $centre     = $this->centre();
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $pastYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($activeYear);
        $admin = $this->admin();
        $this->persist($centre, $activeYear, $pastYear, $admin);

        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/curso/año');
        $this->client->request('POST', '/curso/año/activo', [
            '_token' => $this->csrfToken('reset_year'),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($this->client->getRequest());
        try {
            /** @var \App\Service\TenantContext $tenantContext */
            $tenantContext = self::getContainer()->get(\App\Service\TenantContext::class);
            $viewYear      = $tenantContext->getViewYear($centre);
        } finally {
            $requestStack->pop();
        }
        self::assertNotNull($viewYear);
        self::assertSame($activeYear->getId()->toRfc4122(), $viewYear->getId()->toRfc4122(), 'resetting must fall back to the active year, not stay on the past one');
    }
}
