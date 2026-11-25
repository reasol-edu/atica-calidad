<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\ListItemRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SenecaListImportControllerTest extends ControllerTestCase
{
    private const GROUPS_CSV = <<<'CSV'
        "Unidad","Tipo","Capacidad prevista"
        "1º DAM","PURA",30
        "2º DAM","PURA",20
        CSV;

    private const SUBJECTS_CSV = <<<'CSV'
        "Curso","Materia","Unidad","Profesor/a"
        "1º F.P.I.G.S.","Sistemas informáticos","1º DAM","Sánchez Ramos, Enrique"
        CSV;

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

    private function uploadedCsv(string $content, string $filename = 'seneca.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'seneca_import_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    private function director(EducationalCentre $centre): Teacher
    {
        $director = $this->teacher('director');
        $centre->getAdmins()->add($director);

        return $director;
    }

    // ── permission gating ────────────────────────────────────────────────────

    public function testGroupsImportFormDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSubjectsImportFormDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-materias-seneca");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ── GET renders the upload form ─────────────────────────────────────────

    public function testGetRendersTheUploadForm(): void
    {
        $centre   = $this->centre();
        $director = $this->director($centre);
        $this->persist($centre, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ── POST (no confirm) renders a preview, does not touch the database ────

    public function testUploadingAGroupsCsvRendersAPreviewWithoutPersistingAnything(): void
    {
        $centre   = $this->centre();
        $director = $this->director($centre);
        $this->persist($centre, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca",
            ['_token' => $this->csrfToken('import_seneca_list_groups'), 'root_name' => 'Grupo'],
            ['csv' => $this->uploadedCsv(self::GROUPS_CSV)],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('1º DAM', $body);
        self::assertStringContainsString('2º DAM', $body);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertCount(0, $items->findRootsByCentre($centre), 'a preview must not write to the database');
    }

    public function testMissingColumnShowsAnErrorInsteadOfAPreview(): void
    {
        $centre   = $this->centre();
        $director = $this->director($centre);
        $this->persist($centre, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-materias-seneca");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-materias-seneca",
            ['_token' => $this->csrfToken('import_seneca_list_subjects'), 'root_name' => 'Materia'],
            ['csv' => $this->uploadedCsv(self::GROUPS_CSV)],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Materia', (string) $this->client->getResponse()->getContent());
    }

    public function testNoFileSelectedShowsAnError(): void
    {
        $centre   = $this->centre();
        $director = $this->director($centre);
        $this->persist($centre, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca",
            ['_token' => $this->csrfToken('import_seneca_list_groups'), 'root_name' => 'Grupo'],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ── confirm applies the import ──────────────────────────────────────────

    public function testConfirmingTheSubjectsPreviewCreatesTheNestedTree(): void
    {
        $centre   = $this->centre();
        $director = $this->director($centre);
        $this->persist($centre, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-materias-seneca");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-materias-seneca",
            ['_token' => $this->csrfToken('import_seneca_list_subjects'), 'root_name' => 'Materia'],
            ['csv' => $this->uploadedCsv(self::SUBJECTS_CSV)],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $csvBase64 = $this->extractHiddenField('csv_content');

        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-materias-seneca",
            [
                '_token'      => $this->csrfToken('import_seneca_list_subjects'),
                'root_name'   => 'Materia',
                'csv_content' => $csvBase64,
                'confirm'     => '1',
            ],
        );

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/responsabilidades/listas"));

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Materia', $roots[0]->getName());
        $groups = $items->findChildrenByParent($roots[0]);
        self::assertSame(['1º DAM'], array_map(static fn (ListItem $i): string => $i->getName(), $groups));
        $subjects = $items->findChildrenByParent($groups[0]);
        self::assertSame(['Sistemas informáticos'], array_map(static fn (ListItem $i): string => $i->getName(), $subjects));
    }

    public function testConfirmingWithDeactivateInsteadOfDeleteKeepsTheUnusedOrphanButInactive(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $stale  = (new ListItem())->setEducationalCentre($centre)->setName('Grupo obsoleto')->setPosition(0);
        $stale->setParent($root);
        $director = $this->director($centre);
        $this->persist($centre, $root, $stale, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca",
            ['_token' => $this->csrfToken('import_seneca_list_groups'), 'root_name' => 'Grupo'],
            ['csv' => $this->uploadedCsv(self::GROUPS_CSV)],
        );
        $csvBase64 = $this->extractHiddenField('csv_content');

        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca",
            [
                '_token'        => $this->csrfToken('import_seneca_list_groups'),
                'root_name'     => 'Grupo',
                'csv_content'   => $csvBase64,
                'confirm'       => '1',
                'unused_action' => 'deactivate',
            ],
        );

        self::assertTrue($this->client->getResponse()->isRedirect("/centro/{$centreId}/responsabilidades/listas"));

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $roots    = $items->findRootsByCentre($centre);
        $children = $items->findChildrenByParent($roots[0]);
        $obsolete = array_values(array_filter($children, static fn (ListItem $i): bool => $i->getName() === 'Grupo obsoleto'));
        self::assertCount(1, $obsolete, 'deactivate must keep the item, not remove it');
        self::assertFalse($obsolete[0]->isActive());
    }

    public function testAnItemInUseIsDeactivatedNotDeletedEvenWhenDeleteIsChosen(): void
    {
        $centre  = $this->centre();
        $root    = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $inUse   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo en uso')->setPosition(0);
        $inUse->setParent($root);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil')->setListItem($inUse);
        $director = $this->director($centre);
        $this->persist($centre, $root, $inUse, $profile, $director);
        $centreId = $centre->getId()->toRfc4122();

        $this->loginAs($director, $centre);
        $this->client->request('GET', "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca");
        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca",
            ['_token' => $this->csrfToken('import_seneca_list_groups'), 'root_name' => 'Grupo'],
            ['csv' => $this->uploadedCsv(self::GROUPS_CSV)],
        );
        $csvBase64 = $this->extractHiddenField('csv_content');

        $this->client->request(
            'POST',
            "/centro/{$centreId}/responsabilidades/listas/importar-grupos-seneca",
            [
                '_token'        => $this->csrfToken('import_seneca_list_groups'),
                'root_name'     => 'Grupo',
                'csv_content'   => $csvBase64,
                'confirm'       => '1',
                'unused_action' => 'delete',
            ],
        );

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $roots    = $items->findRootsByCentre($centre);
        $children = $items->findChildrenByParent($roots[0]);
        $still    = array_values(array_filter($children, static fn (ListItem $i): bool => $i->getName() === 'Grupo en uso'));
        self::assertCount(1, $still, 'must survive since it is associated with a specific profile');
        self::assertFalse($still[0]->isActive());
    }

    // ── teachers and layout (subjects import) ───────────────────────────────

    private const TEACHERS_CSV = <<<'CSV'
        "Curso","Materia","Unidad","Profesor/a"
        "1º ESO","Matemáticas","1º ESO A","Bravo Escudero, Noelia"
        "1º ESO","Lengua","1º ESO A","Gómez Pérez, Ana"
        CSV;

    /**
     * A director of a centre with an active course that Noelia Bravo Escudero is in.
     *
     * @return array{0: EducationalCentre, 1: Teacher}
     */
    private function centreWithCourse(): array
    {
        $centre   = $this->centre();
        $director = $this->director($centre);
        $noelia   = (new Teacher(new PersonName('Noelia', 'Bravo Escudero')))->setUsername('noelia');
        $year     = (new \App\Entity\AcademicYear())->setName('2026-2027')->setEducationalCentre($centre)->addTeacher($noelia)->addTeacher($director);
        $this->persist($centre, $director, $noelia, $year);
        $centre->setActiveAcademicYear($year);
        $this->em->flush();

        return [$centre, $director];
    }

    /** @param array<string, string> $extra */
    private function previewSubjects(EducationalCentre $centre, string $csv, array $extra = []): void
    {
        $url = '/centro/' . $centre->getId()->toRfc4122() . '/responsabilidades/listas/importar-materias-seneca';
        $this->client->request('GET', $url);
        $this->client->request('POST', $url, ['_token' => $this->csrfToken('import_seneca_list_subjects'), 'root_name' => 'Materias'] + $extra, ['csv' => $this->uploadedCsv($csv)]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** @param array<string, string> $fields */
    private function postSubjects(EducationalCentre $centre, array $fields): void
    {
        $this->client->request(
            'POST',
            '/centro/' . $centre->getId()->toRfc4122() . '/responsabilidades/listas/importar-materias-seneca',
            ['_token' => $this->csrfToken('import_seneca_list_subjects'), 'root_name' => 'Materias'] + $fields,
        );
    }

    public function testWithoutTheTeacherColumnTheBoxIsDisabledAndSaysWhy(): void
    {
        [$centre, $director] = $this->centreWithCourse();
        $this->loginAs($director, $centre);
        $this->previewSubjects($centre, "\"Materia\",\"Unidad\"\n\"Matemáticas\",\"1º ESO A\"\n");

        $box = $this->client->getCrawler()->filter('[data-testid="with-teachers"]');
        self::assertNotNull($box->attr('disabled'));
        self::assertNull($box->attr('checked'));
        self::assertStringContainsString('El fichero no trae la columna «Profesor/a».', (string) $this->client->getResponse()->getContent());
    }

    public function testTheTeachersArePreviewedAndAssociatedAndCanBeLeftOut(): void
    {
        [$centre, $director] = $this->centreWithCourse();
        $this->loginAs($director, $centre);
        $this->previewSubjects($centre, self::TEACHERS_CSV);

        $crawler = $this->client->getCrawler();
        self::assertNotNull($crawler->filter('[data-testid="with-teachers"]')->attr('checked'), 'checked when the file has the column');
        $summary = $crawler->filter('[data-testid="teacher-summary"]')->text();
        self::assertStringContainsString('1 docente asociado', $summary);
        self::assertStringContainsString('1 no encontrado', $summary);
        self::assertStringContainsString('Docente no encontrado: «Gómez Pérez, Ana»', $summary);
        self::assertStringContainsString('+ Noelia Bravo Escudero', $summary);
        $csv = $this->extractHiddenField('csv_content');

        // Unticking the box previews again with the same file, without the teachers.
        $this->postSubjects($centre, ['csv_content' => $csv, 'options' => '1', 'layout' => 'group']);
        self::assertSame(0, $this->client->getCrawler()->filter('[data-testid="teacher-summary"]')->count());

        $this->postSubjects($centre, ['csv_content' => $csv, 'options' => '1', 'layout' => 'group', 'with_teachers' => '1', 'confirm' => '1']);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items  = self::getContainer()->get(ListItemRepository::class);
        $group  = $items->findChildrenByParent($items->findRootsByCentre($centre)[0])[0];
        $byName = [];
        foreach ($items->findChildrenByParent($group) as $subject) {
            $byName[$subject->getName()] = array_map(static fn (Teacher $t): string => $t->getUsername(), $subject->getTeachers());
        }
        self::assertSame(['Matemáticas' => ['noelia'], 'Lengua' => []], $byName);
    }

    public function testTheOtherLayoutWarnsAndIsOnlyAppliedOnceAcknowledged(): void
    {
        [$centre, $director] = $this->centreWithCourse();
        $root  = (new ListItem())->setEducationalCentre($centre)->setName('Materias')->setPosition(0);
        $group = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A')->setPosition(0)->setParent($root);
        $maths = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas')->setPosition(0)->setParent($group);
        $this->persist($root, $group, $maths);
        $this->loginAs($director, $centre);

        $this->previewSubjects($centre, self::TEACHERS_CSV);
        $crawler = $this->client->getCrawler();
        self::assertNotNull($crawler->filter('input[name="layout"][value="group"]')->attr('checked'), 'the layout the list has comes chosen');
        self::assertSame(0, $crawler->filter('[data-testid="layout-mismatch"]')->count());
        $csv = $this->extractHiddenField('csv_content');

        $this->postSubjects($centre, ['csv_content' => $csv, 'options' => '1', 'layout' => 'subject', 'with_teachers' => '1']);
        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('La lista está organizada por grupo.', $crawler->filter('[data-testid="layout-mismatch"]')->text());
        self::assertNotNull($crawler->filter('#seneca-apply')->attr('disabled'));

        // Without the box, the request is refused whatever the page did.
        $this->postSubjects($centre, ['csv_content' => $csv, 'options' => '1', 'layout' => 'subject', 'with_teachers' => '1', 'confirm' => '1']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Entiendo que se crearán elementos nuevos', (string) $this->client->getResponse()->getContent());

        $this->postSubjects($centre, ['csv_content' => $csv, 'options' => '1', 'layout' => 'subject', 'with_teachers' => '1', 'confirm' => '1', 'layout_ack' => '1']);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $top   = $items->findChildrenByParent($items->findRootsByCentre($centre)[0]);
        self::assertSame(['Matemáticas', 'Lengua'], array_map(static fn (ListItem $i): string => $i->getName(), $top), 'by subject now; the old group went');
    }

    private function extractHiddenField(string $name): string
    {
        $crawler = $this->client->getCrawler();
        $node    = $crawler->filter('input[name="' . $name . '"]');
        self::assertGreaterThan(0, $node->count());
        $value = $node->attr('value');
        self::assertNotNull($value);

        return $value;
    }
}
