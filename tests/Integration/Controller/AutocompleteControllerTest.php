<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

final class AutocompleteControllerTest extends ControllerTestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
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

    public function testUnsupportedMethodIs405(): void
    {
        $centre  = $this->centre();
        $admin   = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('POST', '/autocomplete-app/teacher_admin');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownAliasIs404(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/autocomplete-app/no_existe');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ── teacher_admin (ROLE_ADMIN only) ─────────────────────────────────────

    public function testTeacherAdminAliasDeniedToANonAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/autocomplete-app/teacher_admin?query=doc');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTeacherAdminAliasGrantedToAnAdmin(): void
    {
        $centre  = $this->centre();
        $admin   = $this->admin();
        $match   = $this->teacher('docente_buscado');
        $this->persist($centre, $admin, $match);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/autocomplete-app/teacher_admin?query=buscado');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{results: list<array{value: string, text: string}>, next_page: mixed} $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['results']);
        self::assertSame($match->getId()->toRfc4122(), $payload['results'][0]['value']);
        self::assertArrayHasKey('next_page', $payload);
        self::assertNull($payload['next_page']);
    }

    // ── teacher_centre (ROLE_ADMIN, or RESPONSIBILITIES scoped to an academicYearId) ────────────

    public function testTeacherCentreAliasWithoutAnAcademicYearRequiresAdmin(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/autocomplete-app/teacher_centre?query=doc');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTeacherCentreAliasWithoutAnAcademicYearGrantedToAnAdmin(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $match  = $this->teacher('docente_buscado');
        $this->persist($centre, $admin, $match);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', '/autocomplete-app/teacher_centre?query=buscado');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTeacherCentreAliasWithAnUnknownAcademicYearIdIs403(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/autocomplete-app/teacher_centre?query=doc&academicYearId=00000000-0000-0000-0000-000000000000');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTeacherCentreAliasDeniedToATeacherWithoutResponsibilitiesOnThatYearsCentre(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/autocomplete-app/teacher_centre?query=doc&academicYearId={$year->getId()->toRfc4122()}");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTeacherCentreAliasGrantedToTheCentresQualityManagerForThatYear(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $qm     = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $match = $this->teacher('docente_buscado');
        $year->getTeachers()->add($match);

        $this->persist($centre, $year, $qm, $match);

        $this->loginAs($qm, $centre);
        $this->client->request('GET', "/autocomplete-app/teacher_centre?query=buscado&academicYearId={$year->getId()->toRfc4122()}");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array{results: list<array{value: string, text: string}>} $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['results']);
        self::assertSame($match->getId()->toRfc4122(), $payload['results'][0]['value']);
    }

    /** A quality manager of a DIFFERENT centre must not be granted access via an unrelated centre's academic year. */
    public function testTeacherCentreAliasDeniedToAQualityManagerOfAnotherCentre(): void
    {
        $centreA = $this->centre('11111111');
        $centreB = $this->centre('22222222');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centreA);
        $qmOfB   = $this->teacher('calidad_b');
        $centreB->getQualityManagers()->add($qmOfB);

        $this->persist($centreA, $centreB, $year, $qmOfB);

        $this->loginAs($qmOfB, $centreB);
        $this->client->request('GET', "/autocomplete-app/teacher_centre?query=doc&academicYearId={$year->getId()->toRfc4122()}");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
