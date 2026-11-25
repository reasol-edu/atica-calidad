<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormSubmission;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\FormSubmissionRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormDeliveredRows;
use App\Service\Form\FormModelService;
use App\Service\Form\FormSubmissionService;
use App\Tests\Integration\ControllerTestCase;

/**
 * A table with a row per delivery of another activity (fase 1): the department's report reads the reviews of its subjects — only
 * those of its department —, with their state, the values of the named fields its columns read and what the head writes in its own
 * column; what a browser sends for the cells read is ignored, and «Actualizar» reads them again.
 */
final class FormDeliveredRowsTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private SpecificProfile $head;
    private ListItem $informatica;
    private ListItem $matematicas;
    private ListItem $lengua;
    private ListItem $programacion;
    private ListItem $bases;
    private ListItem $algebra;
    private Activity $reviews;
    private Activity $report;
    private Teacher $claudia;
    private Teacher $pablo;
    private Teacher $admin;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section      = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $models       = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $reviewFolder = (new Folder())->setDocumentSection($section)->setName('Revisiones');
        $reportFolder = (new Folder())->setDocumentSection($section)->setName('Informes');

        $departamento      = (new ListItem())->setEducationalCentre($this->centre)->setName('Departamento');
        $this->informatica = (new ListItem())->setEducationalCentre($this->centre)->setName('Informática')->setParent($departamento);
        $this->matematicas = (new ListItem())->setEducationalCentre($this->centre)->setName('Matemáticas')->setParent($departamento);
        $this->lengua      = (new ListItem())->setEducationalCentre($this->centre)->setName('Lengua')->setParent($departamento);
        $subjects          = (new ListItem())->setEducationalCentre($this->centre)->setName('Materia');
        $this->head        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefe/a de Departamento')->setListItem($departamento);

        $this->programacion = (new ListItem())->setEducationalCentre($this->centre)->setName('Programación')->setParent($subjects)->setPosition(0);
        $this->programacion->setAssociation($this->head, $this->informatica);
        $this->bases = (new ListItem())->setEducationalCentre($this->centre)->setName('Bases de datos')->setParent($subjects)->setPosition(1);
        $this->bases->setAssociation($this->head, $this->informatica);
        $this->algebra = (new ListItem())->setEducationalCentre($this->centre)->setName('Álgebra')->setParent($subjects)->setPosition(2);
        $this->algebra->setAssociation($this->head, $this->matematicas);

        $reviewFolder->addUploadProfile($this->head);
        $reportFolder->addUploadProfile($this->head);
        $category      = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->reviews = (new Activity())->setCategory($category)->setTitle('Revisión trimestral PD')->setStart(1, 9)->setEnd(30, 6)->setFolder($reviewFolder)->setListItem($subjects);
        $this->report  = (new Activity())->setCategory($category)->setTitle('Informe de evaluación')->setStart(1, 9)->setEnd(30, 6)->setFolder($reportFolder);

        $this->claudia = (new Teacher(new PersonName('Claudia', 'Núñez')))->setUsername('claudia');
        $this->pablo   = (new Teacher(new PersonName('Pablo', 'Sánchez')))->setUsername('pablo');
        $this->admin   = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);

        $this->persist(
            $this->centre, $section, $models, $reviewFolder, $reportFolder, $departamento, $this->informatica, $this->matematicas, $this->lengua,
            $subjects, $this->head, $this->programacion, $this->bases, $this->algebra, $category, $this->reviews, $this->report,
            $this->claudia, $this->pablo, $this->admin,
            new SpecificProfileAssignment($this->head, $this->informatica, $this->claudia),
            new SpecificProfileAssignment($this->head, $this->matematicas, $this->pablo),
        );
        // The course the heads are in: a profile row only counts with somebody of the active course in it.
        $year = (new \App\Entity\AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre)->addTeacher($this->claudia)->addTeacher($this->pablo);
        $this->persist($year);
        $this->centre->setActiveAcademicYear($year);
        $this->flush();

        $service = self::getContainer()->get(FormModelService::class);
        $this->keys = [
            'hours' => FormDefinition::newKey(), 'done' => FormDefinition::newKey(), 'notes' => FormDefinition::newKey(),
            'table' => FormDefinition::newKey(), 'colHours' => FormDefinition::newKey(), 'colDone' => FormDefinition::newKey(), 'colOwn' => FormDefinition::newKey(),
        ];
        $source = $service->create($models, 'Revisión trimestral PD', 'REV');
        $service->saveDraft($source, ['version' => 1, 'sections' => [['key' => FormDefinition::newKey(), 'title' => 'Datos', 'blocks' => [
            ['key' => $this->keys['hours'], 'type' => 'field', 'fieldType' => 'number', 'name' => 'horas_previstas', 'label' => 'Horas previstas'],
            ['key' => $this->keys['done'], 'type' => 'field', 'fieldType' => 'number', 'name' => 'horas_impartidas', 'label' => 'Horas impartidas'],
            ['key' => $this->keys['notes'], 'type' => 'field', 'fieldType' => 'long', 'name' => 'observaciones', 'label' => 'Observaciones'],
        ]]]], $source->getDraftVersion(), $this->admin);
        $service->sendToReview($source, $this->admin, null);

        $target = $service->create($models, 'Informe de evaluación', 'INF');
        $service->saveDraft($target, ['version' => 1, 'sections' => [['key' => FormDefinition::newKey(), 'title' => 'Resultados', 'blocks' => [
            ['key' => $this->keys['table'], 'type' => 'table', 'mode' => 'delivered', 'label' => 'Resultados de las materias', 'sourceModel' => $source->getId()->toRfc4122(), 'sourceScope' => 'own', 'columns' => [
                ['key' => $this->keys['colHours'], 'label' => 'Previstas', 'fieldType' => 'number', 'sourceField' => 'horas_previstas', 'total' => 'sum'],
                ['key' => $this->keys['colDone'], 'label' => 'Impartidas', 'fieldType' => 'number', 'sourceField' => 'horas_impartidas'],
                ['key' => $this->keys['colOwn'], 'label' => 'Valoración', 'fieldType' => 'long', 'required' => true],
            ]],
        ]]]], $target->getDraftVersion(), $this->admin);
        $service->sendToReview($target, $this->admin, null);

        $this->reviews->setFormModel($source);
        $this->report->setFormModel($target);
        $this->report->setDeliverySource($this->keys['table'], $this->reviews);
        $this->flush();
        $this->clear();
    }

    /**
     * Forgets the entities, and what the services remember of them (the centre's tree and profile rows, kept per request): with the
     * old instances there, a profile would not be the same object as the one just loaded.
     */
    private function clear(): void
    {
        $this->em->clear();
        self::getContainer()->get(\App\Service\ProfileAssignmentRowBuilder::class)->reset();
        self::getContainer()->get(\App\Repository\ListItemRepository::class)->reset();
        self::getContainer()->get(\App\Service\ActivitySubmissionSlotBuilder::class)->reset();
    }

    private function slotKey(ListItem $subprofile, ?ListItem $leaf = null): string
    {
        return implode(':', [$this->head->getId()->toRfc4122(), $subprofile->getId()->toRfc4122(), $leaf?->getId()->toRfc4122() ?? '', '']);
    }

    private function fresh(Activity $activity): Activity
    {
        $found = $this->em->getRepository(Activity::class)->find($activity->getId());
        self::assertNotNull($found);

        return $found;
    }

    /** Fills the review of a subject (as Claudia, the head of Informática) and delivers it unless $draftOnly. */
    private function review(ListItem $subprofile, ListItem $subject, int $hours, bool $draftOnly = false): void
    {
        $service    = self::getContainer()->get(FormSubmissionService::class);
        $teacher    = $this->em->getRepository(Teacher::class)->find($subprofile === $this->informatica ? $this->claudia->getId() : $this->pablo->getId());
        $slot       = $service->slotFor($this->fresh($this->reviews), $this->slotKey($subprofile, $subject));
        $sl = self::getContainer()->get(\App\Service\ActivitySubmissionSlotBuilder::class)->buildSlots($this->fresh($this->reviews));
        self::assertNotNull($slot);
        self::assertNotNull($teacher);
        $submission = $service->open($this->fresh($this->reviews), $slot);
        $service->save($submission, ['fields' => [$this->keys['hours'] => $hours, $this->keys['done'] => $hours - 2, $this->keys['notes'] => '<p>Bien</p>']], $submission->getDraftVersion(), $teacher);
        if (!$draftOnly) {
            $service->deliver($submission, $slot, $teacher);
        }
        $this->clear();
    }

    /** @return array<string, array{sources: array<string, mixed>, values: array<string, array<string, mixed>>, columns: list<string>}> */
    private function read(ListItem $subprofile): array
    {
        $report     = $this->fresh($this->report);
        $definition = self::getContainer()->get(\App\Repository\FormModelRevisionRepository::class)->findInForce($report->getFormModel())->getDefinition();
        $cycle      = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($report);

        return self::getContainer()->get(FormDeliveredRows::class)->read($definition, $report, $cycle, $this->slotKey($subprofile));
    }

    public function testTheHeadSeesTheDeliveriesOfTheSubjectsOfTheirDepartmentWithTheirStateAndValues(): void
    {
        $this->review($this->informatica, $this->programacion, 30);
        $this->review($this->informatica, $this->bases, 20, draftOnly: true);
        $this->review($this->matematicas, $this->algebra, 40);

        $reading = $this->read($this->informatica)[$this->keys['table']];
        $rows    = $reading['sources']['rows'];

        self::assertSame('Revisión trimestral PD', $reading['sources']['title']);
        self::assertFalse($reading['sources']['assumedAll']);
        self::assertSame(['Programación', 'Bases de datos'], array_column($rows, 'label'), 'not Álgebra: another department');
        self::assertSame(['delivered', 'draft'], array_column($rows, 'status'));
        self::assertSame(['approved', null], array_column($rows, 'approval'));
        self::assertSame(FormDeliveredRows::rowKey($this->slotKey($this->informatica, $this->programacion)), $rows[0]['key']);
        self::assertSame([$rows[0]['key'] => [$this->keys['colHours'] => 30, $this->keys['colDone'] => 28]], $reading['values'], 'only what was delivered');
    }

    public function testAllTheDeliveriesOrAllOfThemWhenTheHeadHasNoSubjectOrNoActivityIsChosen(): void
    {
        $this->review($this->matematicas, $this->algebra, 40);

        $reading = $this->read($this->lengua)[$this->keys['table']];
        self::assertTrue($reading['sources']['assumedAll'], 'Lengua has no subject: it sees them all, and is told so');
        self::assertCount(3, $reading['sources']['rows']);

        $report = $this->fresh($this->report);
        $report->setDeliverySource($this->keys['table'], null);
        $this->flush();
        $this->clear();
        $none = $this->read($this->informatica)[$this->keys['table']];
        self::assertNull($none['sources']['activity']);
        self::assertSame([], $none['sources']['rows']);

        // A source in the trash counts as none chosen.
        $report  = $this->fresh($this->report);
        $reviews = $this->fresh($this->reviews);
        $report->setDeliverySource($this->keys['table'], $reviews);
        $reviews->moveToTrash(new \DateTimeImmutable(), $this->em->getRepository(Teacher::class)->find($this->admin->getId()));
        $this->flush();
        $this->clear();
        self::assertNull($this->read($this->informatica)[$this->keys['table']]['sources']['activity']);
    }

    public function testReadingTenDeliveriesCostsWhatReadingTwoDoes(): void
    {
        $this->review($this->informatica, $this->programacion, 30);
        $this->review($this->informatica, $this->bases, 20);
        $few = $this->queriesOfReading();

        for ($i = 0; $i < 8; ++$i) {
            $subjects = $this->em->getRepository(ListItem::class)->find($this->programacion->getId())->getParent();
            $subject  = (new ListItem())->setEducationalCentre($subjects->getEducationalCentre())->setName('Módulo ' . $i)->setParent($subjects)->setPosition(10 + $i);
            $subject->setAssociation($this->em->getRepository(SpecificProfile::class)->find($this->head->getId()), $this->em->getRepository(ListItem::class)->find($this->informatica->getId()));
            $this->persist($subject);
            $this->clear();
            $this->review($this->informatica, $subject, 10 + $i);
        }
        $many = $this->queriesOfReading();

        self::assertSame(10, count($this->read($this->informatica)[$this->keys['table']]['sources']['rows']));
        self::assertSame($few[0], $many[0], "ten deliveries cost what two do:\n--- two\n" . implode("\n", $few[1]) . "\n--- ten\n" . implode("\n", $many[1]));
    }

    /** @return array{0: int, 1: list<string>} how many queries a reading makes (from a clean state, as a request would) and which */
    private function queriesOfReading(): array
    {
        $report     = $this->fresh($this->report);
        $definition = self::getContainer()->get(\App\Repository\FormModelRevisionRepository::class)->findInForce($report->getFormModel())->getDefinition();
        $cycle      = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($report);
        $holder     = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        self::getContainer()->get(FormDeliveredRows::class)->read($definition, $report, $cycle, $this->slotKey($this->informatica));
        $queries = array_map(static fn (array $q): string => substr((string) ($q['sql'] ?? ''), 0, 120), $holder->getData()['default'] ?? []);
        $this->clear();

        return [count($queries), $queries];
    }

    private function submission(): FormSubmission
    {
        $report = $this->fresh($this->report);
        $cycle  = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($report);
        $found  = self::getContainer()->get(FormSubmissionRepository::class)->findBySlot($report, $this->slotKey($this->informatica), $cycle);
        self::assertNotNull($found);

        return $found;
    }

    private function url(string $suffix = ''): string
    {
        return '/actividades/' . $this->report->getId()->toRfc4122() . '/formulario/' . $this->slotKey($this->informatica) . $suffix;
    }

    private function csrf(FormSubmission $submission): string
    {
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken('form_fill_' . $submission->getId()->toRfc4122())->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    /** @param array<string, mixed> $body */
    private function post(string $suffix, array $body, string $token): void
    {
        $this->client->request('POST', $this->url($suffix), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testOpeningReadsTheDeliveriesTheBrowserCannotChangeThemAndActualizarReadsThemAgain(): void
    {
        $this->review($this->informatica, $this->programacion, 30);

        $this->loginAs($this->claudia, $this->centre);
        $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->clear();
        $submission = $this->submission();
        $data       = $submission->getDraftData();
        $rowKey     = FormDeliveredRows::rowKey($this->slotKey($this->informatica, $this->programacion));
        self::assertSame(2, $submission->getDraftVersion(), 'opening stored what it read (the holder of the draft)');
        self::assertNull($submission->getUpdatedBy(), 'nobody\'s edit');
        self::assertSame(30, $data['tables'][$this->keys['table']][0][$this->keys['colHours']]);

        // Opening again with nothing new does not store anything.
        $this->client->request('GET', $this->url());
        $this->clear();
        self::assertSame(2, $this->submission()->getDraftVersion());

        // A save that says 999 hours and forges the sources: the hours stay, the valoración is kept.
        $token = $this->csrf($submission);
        $this->post('/guardar', ['version' => 2, 'data' => [
            'tables'  => [$this->keys['table'] => [['_key' => $rowKey, $this->keys['colHours'] => 999, $this->keys['colOwn'] => 'Buen trimestre']]],
            'sources' => [$this->keys['table'] => ['activity' => null, 'rows' => []]],
        ]], $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->clear();
        $saved = $this->submission()->getDraftData();
        self::assertSame(30, $saved['tables'][$this->keys['table']][0][$this->keys['colHours']]);
        self::assertSame('Buen trimestre', $saved['tables'][$this->keys['table']][0][$this->keys['colOwn']]);
        self::assertCount(2, $saved['sources'][$this->keys['table']]['rows']);

        // Another delivery comes in; «Actualizar» reads it, keeps the valoración and says what changed.
        $this->review($this->informatica, $this->bases, 20);
        $this->post('/actualizar-entregas', ['version' => 1], $token);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'a stale version');

        $this->post('/actualizar-entregas', ['version' => 3], 'wrong');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->post('/actualizar-entregas', ['version' => 3], $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(4, $answer['version']);
        self::assertSame(1, $answer['changed']['count']);
        self::assertSame([['table' => $this->keys['table'], 'row' => FormDeliveredRows::rowKey($this->slotKey($this->informatica, $this->bases)), 'column' => $this->keys['colHours']], ['table' => $this->keys['table'], 'row' => FormDeliveredRows::rowKey($this->slotKey($this->informatica, $this->bases)), 'column' => $this->keys['colDone']]], $answer['changed']['cells']);
        self::assertSame('Buen trimestre', $answer['tables'][$this->keys['table']][0][$this->keys['colOwn']]);
        self::assertSame(['delivered', 'delivered'], array_column($answer['rows'][$this->keys['table']], 'status'));
        // The own column is required only of the delivered rows: both are now, and only Bases de datos lacks it.
        self::assertCount(1, array_filter($answer['problems'], static fn (array $p): bool => $p['code'] === 'required'));
    }
}
