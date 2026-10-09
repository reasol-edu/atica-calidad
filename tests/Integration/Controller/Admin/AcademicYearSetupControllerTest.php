<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\AcademicYearRepository;
use App\Repository\EducationalCentreRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AcademicYearSetupControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username, bool $admin = false): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
        // A global admin reaches any centre without belonging to one of its years, so logging in
        // doesn't add a membership year of its own to the ones under test.
        $teacher->setAdmin($admin);

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

    private function page(EducationalCentre $centre): Crawler
    {
        $crawler = $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/cursos/preparar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    /** @return array<string, string> step key → done / todo / blocked */
    private function states(Crawler $crawler): array
    {
        $states = [];
        $crawler->filter('[data-step]')->each(static function (Crawler $li) use (&$states): void {
            $states[(string) $li->attr('data-step')] = (string) $li->attr('data-state');
        });

        return $states;
    }

    public function testDeniedWithoutSectionPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/cursos/preparar');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** Create the suggested year, activate it, copy the teachers of the previous one: each step turns done in turn. */
    public function testWalksThroughTheSteps(): void
    {
        $centre = $this->centre();
        $old    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($old);
        $admin   = $this->teacher('root', admin: true);
        $stays   = $this->teacher('sigue');
        $leaves  = $this->teacher('seva');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $stays->addAcademicYear($old);
        $leaves->addAcademicYear($old);
        $this->persist($centre, $old, $admin, $stays, $leaves, $profile, new SpecificProfileAssignment($profile, null, $leaves));
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $crawler = $this->page($centre);
        // "2025-2026" is the latest year so far; the form suggests the next one's name.
        self::assertSame('2026-2027', $crawler->filter('input[name=name]')->attr('value'));

        // 1. Create
        $this->client->request('POST', "/centro/{$centreId}/cursos/preparar/crear", ['_token' => $this->csrfToken('year_setup_create_' . $centreId), 'name' => '2026-2027']);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $crawler = $this->page($centre);
        self::assertSame(['create' => 'done', 'activate' => 'todo', 'teachers' => 'blocked', 'non_working_days' => 'blocked', 'assignments' => 'blocked', 'activities' => 'blocked'], $this->states($crawler));

        // 2. Activate
        $this->client->submit($crawler->filter('[data-step=activate] form')->form());
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        self::assertSame('2026-2027', $centres->findByIdWithActiveYear($centreId)?->getActiveAcademicYear()?->getName());
        self::assertSame(['create' => 'done', 'activate' => 'done', 'teachers' => 'todo', 'non_working_days' => 'todo', 'assignments' => 'blocked', 'activities' => 'blocked'], $this->states($this->page($centre)));

        // 3. Copy the teachers of 2025-2026: the assignment of whoever left is then flagged.
        $this->client->request('POST', "/centro/{$centreId}/cursos/preparar/copiar-docentes", ['_token' => $this->csrfToken('year_setup_copy_teachers_' . $centreId), 'source' => $old->getId()->toRfc4122()]);
        $this->client->followRedirect();
        self::assertStringContainsString('Se han añadido 2 docentes de 2025-2026.', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        /** @var AcademicYearRepository $years */
        $years = self::getContainer()->get(AcademicYearRepository::class);
        $new   = $years->findByCentreAndId($centre, (string) $centres->findByIdWithActiveYear($centreId)?->getActiveAcademicYear()?->getId()->toRfc4122());
        self::assertNotNull($new);
        self::assertCount(2, $new->getTeachers());
        // … and someone left after all: out of the new year, their assignment goes stale.
        foreach ($new->getTeachers() as $teacher) {
            if ($teacher->getUsername() === 'seva') {
                $new->removeTeacher($teacher);
            }
        }
        $this->em->persist((new NonWorkingDay())->setAcademicYear($new)->setDate(new \DateTimeImmutable('2026-12-08'))->setDescription('Inmaculada'));
        $this->em->flush();

        $crawler = $this->page($centre);
        self::assertSame(['create' => 'done', 'activate' => 'done', 'teachers' => 'done', 'non_working_days' => 'done', 'assignments' => 'todo', 'activities' => 'done'], $this->states($crawler));
        self::assertStringContainsString('seva, Nombre · Jefatura', $crawler->filter('[data-step=assignments]')->text());
    }

    public function testTheActivityReviewFlagsProfilesNobodyHoldsInTheYear(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $stays    = $this->teacher('queda');
        $year->addTeacher($stays);
        $covered  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $orphaned = (new SpecificProfile())->setEducationalCentre($centre)->setName('Coordinación');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Seguimiento');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Memorias');
        $folder->addUploadProfile($covered)->addUploadProfile($orphaned);
        $withFolder = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder);
        $manual     = (new Activity())->setCategory($category)->setTitle('Lectura del plan')->setStart(1, 9)->setEnd(30, 6);
        $admin      = $this->teacher('root', admin: true);
        $this->persist($centre, $year, $stays, $covered, $orphaned, $category, $section, $folder, $withFolder, $manual, $admin, new SpecificProfileAssignment($covered, null, $stays));
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $crawler = $this->client->request('GET', "/centro/{$centreId}/cursos/preparar/actividades");
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $text = $crawler->filter('main, body')->first()->text();

        self::assertStringContainsString('Memoria', $text);
        self::assertStringContainsString('Alguno de los perfiles a los que se pide no tiene docentes en este curso.', $text);
        self::assertStringContainsString('Coordinación · 0', $text);
        self::assertStringContainsString('Jefatura · 1', $text);
        self::assertStringContainsString('Lectura del plan', $text);
        self::assertStringContainsString('A todo el profesorado del curso', $text);

        // And the checklist step counts only the flagged one (the manual, general activity is fine).
        self::assertSame('todo', $this->states($this->page($centre))['activities']);
    }

    public function testCreateRejectsANameAlreadyInUse(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $admin  = $this->teacher('root', admin: true);
        $this->persist($centre, $year, $admin);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/centro/{$centreId}/cursos/preparar/crear", ['_token' => $this->csrfToken('year_setup_create_' . $centreId), 'name' => '2025-2026']);
        $this->client->followRedirect();

        self::assertStringContainsString('Ya existe un curso académico con ese nombre.', (string) $this->client->getResponse()->getContent());
        /** @var AcademicYearRepository $years */
        $years = self::getContainer()->get(AcademicYearRepository::class);
        self::assertCount(1, $years->findByCentreOrderedByName($centre));
    }
}
