<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Repository\FormValueRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormListOptions;
use App\Service\Form\FormModelService;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Choice fields whose options are the items of a list of Responsabilidades, read live: a new item shows up, a draft keeps what is
 * gone, a delivery freezes the names, and a model travels between centres by the names of its lists.
 */
final class FormListOptionsTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $modelFolder;
    private Folder $activityFolder;
    private SpecificProfile $authors;
    private Activity $activity;
    private Teacher $author;
    private Teacher $admin;
    private FormModel $model;
    private ListItem $departments;
    private ListItem $maths;
    private ListItem $physics;
    private string $fieldKey;
    private string $columnKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre         = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section              = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->modelFolder    = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->activityFolder = (new Folder())->setDocumentSection($section)->setName('Entregas');
        $this->authors        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Profesorado');
        $this->activityFolder->addUploadProfile($this->authors);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->activityFolder);
        $this->author   = (new Teacher(new PersonName('Ana', 'Apellido')))->setUsername('autora');
        $this->admin    = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);

        $this->departments = $this->item('Departamento', null, 0);
        $this->maths       = $this->item('Matemáticas', $this->departments, 0);
        $this->physics     = $this->item('Física', $this->departments, 1);
        $this->persist(
            $this->centre, $section, $this->modelFolder, $this->activityFolder, $this->authors, $category, $this->activity, $this->author, $this->admin,
            new SpecificProfileAssignment($this->authors, null, $this->author),
            $this->departments, $this->maths, $this->physics,
        );

        $this->fieldKey  = FormDefinition::newKey();
        $this->columnKey = FormDefinition::newKey();
        $service         = self::getContainer()->get(FormModelService::class);
        $this->model     = $service->create($this->modelFolder, 'Memoria', 'MD1');
        $model           = $this->em->getRepository(FormModel::class)->find($this->model->getId());
        $admin           = $this->em->getRepository(Teacher::class)->find($this->admin->getId());
        $service->saveDraft($model, $this->definition(), $model->getDraftVersion(), $admin);
        $service->sendToReview($model, $admin, null);
        $this->activity->setFormModel($model);
        $this->flush();
    }

    private function item(string $name, ?ListItem $parent, int $position): ListItem
    {
        $item = (new ListItem())->setEducationalCentre($this->centre)->setName($name)->setPosition($position);
        if ($parent !== null) {
            $item->setParent($parent);
        }

        return $item;
    }

    /** @return array<string, mixed> a choice field and a table whose column is a choice, both fed by the «Departamento» list */
    private function definition(): array
    {
        return ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [
                ['key' => $this->fieldKey, 'type' => 'field', 'name' => 'dep', 'fieldType' => 'choice', 'label' => 'Departamento', 'required' => true, 'options' => [], 'list' => $this->departments->getId()->toRfc4122()],
                ['key' => FormDefinition::newKey(), 'type' => 'table', 'name' => 'reuniones', 'label' => 'Reuniones', 'mode' => 'free', 'rowHeader' => '', 'rows' => [], 'minRows' => 0, 'maxRows' => 5, 'help' => '', 'columns' => [
                    ['key' => $this->columnKey, 'name' => 'dep', 'label' => 'Dpto.', 'fieldType' => 'multichoice', 'required' => false, 'options' => [], 'list' => $this->departments->getId()->toRfc4122()],
                ]],
            ],
        ]]];
    }

    private function slotKey(): string
    {
        return $this->authors->getId()->toRfc4122() . ':::';
    }

    private function url(string $suffix = ''): string
    {
        return '/actividades/' . $this->activity->getId()->toRfc4122() . '/formulario/' . $this->slotKey() . $suffix;
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

    private function submission(): \App\Entity\FormSubmission
    {
        $this->em->clear();
        $activity = $this->em->getRepository(Activity::class)->find($this->activity->getId());
        $cycle    = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($activity);
        $found    = self::getContainer()->get(FormSubmissionRepository::class)->findBySlot($activity, $this->slotKey(), $cycle);
        self::assertNotNull($found);

        return $found;
    }

    /** Opens the form as the author and saves the choice; returns the saved data. */
    private function save(string $itemId): array
    {
        $this->loginAs($this->author, $this->centre);
        $this->client->request('GET', $this->url());
        $submission = $this->submission();
        $sid        = $submission->getId()->toRfc4122();
        $this->client->request('POST', $this->url('/guardar'), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken('form_fill_' . $sid)], json_encode(['version' => $submission->getDraftVersion(), 'data' => ['fields' => [$this->fieldKey => $itemId]]], JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->submission()->getDraftData() ?? [];
    }

    private function deliver(): void
    {
        $sid = $this->submission()->getId()->toRfc4122();
        $this->client->request('POST', $this->url('/entregar'), ['_token' => $this->csrfToken('form_deliver_' . $sid)]);
        self::assertTrue($this->client->getResponse()->isRedirect());
    }

    /** Edits the list as the Responsabilidades screens would. */
    private function change(callable $change): void
    {
        $this->em->clear();
        $change(
            $this->em->getRepository(ListItem::class)->find($this->maths->getId()),
            $this->em->getRepository(ListItem::class)->find($this->departments->getId()),
        );
        $this->em->flush();
        $this->em->clear();
        // The repository keeps the tree it read for the rest of the request: the next read must see the change.
        self::getContainer()->get(\App\Repository\ListItemRepository::class)->reset();
    }

    public function testTheOptionsAreTheLeavesOfTheListAsTheyAreNow(): void
    {
        $lists   = self::getContainer()->get(FormListOptions::class);
        $centre  = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $options = $lists->optionsOf($this->departments->getId()->toRfc4122(), $centre);
        self::assertSame(['Matemáticas', 'Física'], array_column($options, 'label'));
        self::assertSame($this->maths->getId()->toRfc4122(), $options[0]['key']);

        $this->change(function (ListItem $maths, ListItem $departments): void {
            $new = (new ListItem())->setEducationalCentre($maths->getEducationalCentre())->setName('Latín')->setPosition(2)->setParent($departments);
            $this->em->persist($new);
            $maths->setName('Matemáticas y Estadística');
        });
        $centre = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        self::assertSame(['Matemáticas y Estadística', 'Física', 'Latín'], array_column($lists->optionsOf($this->departments->getId()->toRfc4122(), $centre), 'label'));
    }

    public function testADraftSaveKeepsTheSelectedItem(): void
    {
        $data = $this->save($this->maths->getId()->toRfc4122());
        self::assertSame($this->maths->getId()->toRfc4122(), $data['fields'][$this->fieldKey] ?? null);

        // The page the author fills in is handed the options of the list, not the (empty) ones stored in the model.
        $this->client->request('GET', $this->url());
        // (the editor's configuration is JSON in an HTML attribute: the accent is written as an escape, and the escape's backslash too)
        self::assertStringContainsString('Matem&#x5C;u00e1ticas', (string) $this->client->getResponse()->getContent());
    }

    public function testADeactivatedItemSurvivesTheDraftButBlocksTheDelivery(): void
    {
        $this->save($this->maths->getId()->toRfc4122());
        $this->change(static function (ListItem $maths): void {
            $maths->setActive(false);
        });

        // Saving again does not drop it: the author sees it, marked, and is told to choose another one.
        $data = $this->save($this->maths->getId()->toRfc4122());
        self::assertSame($this->maths->getId()->toRfc4122(), $data['fields'][$this->fieldKey] ?? null);
        $this->deliver();
        self::assertTrue($this->submission()->hasDraft(), 'still a draft');

        $data = $this->save($this->physics->getId()->toRfc4122());
        self::assertSame($this->physics->getId()->toRfc4122(), $data['fields'][$this->fieldKey] ?? null);
        $this->deliver();
        self::assertFalse($this->submission()->hasDraft(), 'delivered with an item that is on offer');
    }

    public function testADeletedItemIsKeptInTheDraftAndBlocksTheDelivery(): void
    {
        $this->save($this->maths->getId()->toRfc4122());
        $this->change(function (ListItem $maths): void {
            $this->em->remove($maths);
        });

        $data = $this->save($this->maths->getId()->toRfc4122());
        self::assertSame($this->maths->getId()->toRfc4122(), $data['fields'][$this->fieldKey] ?? null);
        $this->deliver();
        self::assertTrue($this->submission()->hasDraft());
    }

    public function testADeliveredDocumentKeepsTheNamesItWasDeliveredWith(): void
    {
        $this->save($this->maths->getId()->toRfc4122());
        $this->deliver();

        $this->change(function (ListItem $maths): void {
            $maths->setName('Cálculo');
        });
        $this->loginAs($this->admin, $this->centre);
        $filled = self::getContainer()->get(FormSubmissionRevisionRepository::class)->findLatest($this->submission());
        self::assertNotNull($filled);
        self::assertSame([$this->fieldKey => [['key' => $this->maths->getId()->toRfc4122(), 'label' => 'Matemáticas']]], $filled->getOptionsSnapshot());

        $this->client->request('GET', '/formularios/revision/' . $filled->getRevision()->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Matemáticas', $page);
        self::assertStringNotContainsString('Cálculo', $page);

        // And the explorer's index keeps pointing at the item, so the value can still be grouped by it.
        $rows = self::getContainer()->get(FormValueRepository::class)->findAll();
        self::assertContains($this->maths->getId()->toRfc4122(), array_map(static fn (\App\Entity\FormValue $v): ?string => $v->getText(), $rows));
    }

    public function testTheDeleteWarningCountsTheDeliveredDocumentsThatUseTheItems(): void
    {
        $this->save($this->maths->getId()->toRfc4122());
        $this->deliver();

        $values = self::getContainer()->get(FormValueRepository::class);
        self::assertSame(1, $values->countDocumentsUsingItems([$this->maths->getId()->toRfc4122(), $this->physics->getId()->toRfc4122()]));
        self::assertSame(0, $values->countDocumentsUsingItems([$this->physics->getId()->toRfc4122()]));
    }

    public function testTheIndicatorFormOffersTheOptionsOfAListFedChoiceForTheCondition(): void
    {
        // Indicators belong to the centre's active academic year.
        $centre = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $year   = (new \App\Entity\AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $this->persist($year);
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/mejora/indicadores/nuevo');
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="indicator-condition"', $page);
        // The options of «Departamento» by "activity|field", in the attribute the picker reads (accent and backslash escaped).
        self::assertStringContainsString($this->activity->getId()->toRfc4122() . '|' . $this->fieldKey, $page);
        self::assertStringContainsString('Matem&#x5C;u00e1ticas', $page);
        self::assertStringContainsString($this->maths->getId()->toRfc4122(), $page);
    }

    public function testSenecaImportRemovesNothingThatDeliveredDocumentsUseAndWarnsAboutIt(): void
    {
        $this->save($this->maths->getId()->toRfc4122());
        $this->deliver();
        $this->em->clear();

        $importer = self::getContainer()->get(\App\Service\SenecaListImporter::class);
        $centre   = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        // The new file no longer has «Matemáticas»: it would be removed, but a delivered document has it chosen.
        $plan = $importer->plan($centre, 'Departamento', [new \App\Model\SenecaImportNode('Física', [])]);
        self::assertCount(0, $plan->deletions, 'in use by a delivered document: not deleted');
        self::assertContains('Matemáticas', array_map(static fn (\App\Model\SenecaListImportPlanItem $r): string => $r->item?->getName() ?? '', $plan->deactivations));
        self::assertSame(1, $importer->formUsage($plan));
    }

    public function testTheEditorOffersTheListsOfTheCentre(): void
    {
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', '/modelos/' . $this->model->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $page = html_entity_decode((string) $this->client->getResponse()->getContent());
        self::assertMatchesRegularExpression('/"lists":\[\{"id":"' . $this->departments->getId()->toRfc4122() . '","label":"Departamento".{1,200}Matem/su', preg_replace('/\\\\u([0-9a-f]{4})/i', 'M', $page) ?? $page);
    }

    public function testAModelTravelsByTheNamesOfItsLists(): void
    {
        $id = $this->model->getId()->toRfc4122();
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('GET', "/modelos/{$id}/exportar.json");
        self::assertResponseIsSuccessful();
        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($this->departments->getId()->toRfc4122(), $json, 'no id of this centre travels');
        self::assertStringContainsString('"listPath"', $json);

        // Another centre with a list of the same name: the field is linked to it.
        $other     = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Villa');
        $section   = (new DocumentSection())->setEducationalCentre($other)->setName('Sección');
        $folder    = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $theirs    = (new ListItem())->setEducationalCentre($other)->setName('Departamento')->setPosition(0);
        $theirLeaf = (new ListItem())->setEducationalCentre($other)->setName('Latín')->setPosition(0);
        $theirLeaf->setParent($theirs);
        $this->persist($other, $section, $folder, $theirs, $theirLeaf);

        $porter = self::getContainer()->get(\App\Service\Form\FormModelPorter::class);
        $result = $porter->import(json_decode($json, true, 512, JSON_THROW_ON_ERROR), $this->em->getRepository(Folder::class)->find($folder->getId()), 'Importado', null);
        self::assertSame([], $result['unlinked']);
        $block = $result['model']->getDraftDefinition()['sections'][0]['blocks'][0];
        self::assertSame($theirs->getId()->toRfc4122(), $block['list']);

        // A centre without that list: the field is left without options and reported, and the model cannot be sent to review.
        $bare       = (new EducationalCentre())->setCode('11111111')->setName('Vacío')->setCity('Aldea');
        $bareSect   = (new DocumentSection())->setEducationalCentre($bare)->setName('Sección');
        $bareFolder = (new Folder())->setDocumentSection($bareSect)->setName('Modelos');
        $this->persist($bare, $bareSect, $bareFolder);
        $result = $porter->import(json_decode($json, true, 512, JSON_THROW_ON_ERROR), $this->em->getRepository(Folder::class)->find($bareFolder->getId()), 'Sin lista', null);
        self::assertContains('Departamento', $result['unlinked']);
        $block = $result['model']->getDraftDefinition()['sections'][0]['blocks'][0];
        self::assertSame('', $block['list']);
        $problems = self::getContainer()->get(FormModelService::class)->problems(self::getContainer()->get(\App\Service\Form\FormDefinitionNormalizer::class)->normalize($result['model']->getDraftDefinition()), $this->em->getRepository(EducationalCentre::class)->find($bare->getId()));
        self::assertContains('choice_without_options', array_column($problems, 'code'));
    }

    public function testAModelWhoseListIsEmptyCannotBeSentToReview(): void
    {
        $service = self::getContainer()->get(FormModelService::class);
        $centre  = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        self::assertSame([], $service->problems($this->definition(), $centre));

        $this->change(static function (ListItem $maths, ListItem $departments): void {
            $departments->setActive(true);
            $maths->setActive(false);
            foreach ($departments->getChildren() as $child) {
                $child->setActive(false);
            }
        });
        $centre   = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $problems = $service->problems($this->definition(), $centre);
        self::assertContains('choice_list_empty', array_column($problems, 'code'));
    }
}
