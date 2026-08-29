<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\NonWorkingDayRepository;
use App\Tests\Integration\ControllerTestCase;

final class NonWorkingDayControllerTest extends ControllerTestCase
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

    public function testIndexDeniedWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/dias-no-lectivos");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAddCreatesANonWorkingDay(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/dias-no-lectivos");
        $this->client->request('POST', "/centro/{$centreId}/dias-no-lectivos/nuevo", [
            '_token'      => $this->csrfToken('add_non_working_day_' . $centreId),
            'date'        => '2025-10-13',
            'description' => 'Fiesta local',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/dias-no-lectivos"));

        $this->em->clear();
        /** @var NonWorkingDayRepository $days */
        $days     = self::getContainer()->get(NonWorkingDayRepository::class);
        /** @var \App\Repository\AcademicYearRepository $years */
        $years    = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloaded = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        $all = $days->findByAcademicYearOrdered($reloaded);
        self::assertCount(1, $all);
        self::assertSame('Fiesta local', $all[0]->getDescription());
    }

    public function testAddRejectsADuplicateDate(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $existing = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-13'))->setAcademicYear($year);
        $admin    = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $existing, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/dias-no-lectivos");
        $this->client->request('POST', "/centro/{$centreId}/dias-no-lectivos/nuevo", [
            '_token'      => $this->csrfToken('add_non_working_day_' . $centreId),
            'date'        => '2025-10-13',
            'description' => 'Duplicado',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/dias-no-lectivos"));

        $this->em->clear();
        /** @var NonWorkingDayRepository $days */
        $days     = self::getContainer()->get(NonWorkingDayRepository::class);
        /** @var \App\Repository\AcademicYearRepository $years */
        $years    = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloaded = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        self::assertCount(1, $days->findByAcademicYearOrdered($reloaded));
    }

    public function testEditUpdatesTheDescription(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $day   = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-13'))->setDescription('Original')->setAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $day, $admin);
        $centreId = $centre->getId()->toRfc4122();
        $dayId    = $day->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/dias-no-lectivos/{$dayId}/editar");
        $this->client->request('POST', "/centro/{$centreId}/dias-no-lectivos/{$dayId}/editar", [
            '_token'      => $this->csrfToken('edit_non_working_day_' . $dayId),
            'date'        => '2025-10-13',
            'description' => 'Actualizado',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/dias-no-lectivos"));

        $this->em->clear();
        /** @var NonWorkingDayRepository $days */
        $days     = self::getContainer()->get(NonWorkingDayRepository::class);
        /** @var \App\Repository\AcademicYearRepository $years */
        $years    = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloaded = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        $all = $days->findByAcademicYearOrdered($reloaded);
        self::assertSame('Actualizado', $all[0]->getDescription());
    }

    public function testDeleteRemovesTheDay(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $day   = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-13'))->setAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $day, $admin);
        $centreId = $centre->getId()->toRfc4122();
        $dayId    = $day->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/centro/{$centreId}/dias-no-lectivos/{$dayId}/eliminar", [
            '_token' => $this->csrfToken('delete_non_working_day_' . $dayId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/dias-no-lectivos"));

        $this->em->clear();
        /** @var NonWorkingDayRepository $days */
        $days     = self::getContainer()->get(NonWorkingDayRepository::class);
        /** @var \App\Repository\AcademicYearRepository $years */
        $years    = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloaded = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        self::assertCount(0, $days->findByAcademicYearOrdered($reloaded));
    }

    public function testImportSenecaCsvCreatesDays(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $csv = "Fecha,Descripción de la festividad,Afecta al personal docente\n12/10/2025,Fiesta nacional,Si\n";
        $path = tempnam(sys_get_temp_dir(), 'seneca_import_');
        self::assertNotFalse($path);
        file_put_contents($path, $csv);
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'festivos.csv', 'text/csv', null, true);

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/dias-no-lectivos/importar");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/dias-no-lectivos/importar-seneca",
            ['_token' => $this->csrfToken('import_seneca_non_working_days')],
            ['csv' => $upload],
        );

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/dias-no-lectivos"));

        $this->em->clear();
        /** @var NonWorkingDayRepository $days */
        $days     = self::getContainer()->get(NonWorkingDayRepository::class);
        /** @var \App\Repository\AcademicYearRepository $years */
        $years    = self::getContainer()->get(\App\Repository\AcademicYearRepository::class);
        $reloaded = $years->findById($year->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        self::assertCount(1, $days->findByAcademicYearOrdered($reloaded));
    }

    public function testImportRejectsAMissingFileWithAFlashInsteadOfCrashing(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('GET', "/centro/{$centreId}/dias-no-lectivos/importar");
        $this->client->request('POST', "/centro/{$centreId}/dias-no-lectivos/importar", [
            '_token' => $this->csrfToken('import_non_working_days'),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
