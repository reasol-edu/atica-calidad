<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\FormModelRevision;
use App\Entity\FormSubmission;
use App\Entity\FormSubmissionRevision;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ListItemRepository;
use App\Service\ActivityDeadlineChecker;
use App\Service\Form\FormDataExplorer;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormListOptions;
use App\Service\Form\FormModelService;
use App\Service\Form\FormValueIndexer;
use App\Service\SenecaListImporter;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * What the fillable models read must cost the same number of queries whether there are two of it or twenty (no N+1): the lists that feed
 * the choices, the explorer and the indicators over many deliveries, the models screen and the import from Séneca. Each test builds a
 * small and a big case and compares.
 */
final class FormQueryCountTest extends ControllerTestCase
{
    /** @var list<string> the queries of the last measurement, to say what grew when two numbers differ */
    private array $lastQueries = [];
    private EducationalCentre $centre;
    private Folder $folder;
    private Teacher $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section      = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->folder = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->admin  = (new Teacher(new PersonName('Ada', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($this->centre, $section, $this->folder, $this->admin);
    }

    /** Runs $work with the per-request memos cleared and returns how many queries it issued. */
    private function queriesOf(callable $work): int
    {
        self::getContainer()->get(ListItemRepository::class)->reset();
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $work();
        $this->lastQueries = array_map(static fn (array $q): string => substr((string) ($q['sql'] ?? ''), 0, 160), $holder->getData()['default'] ?? []);

        return \count($this->lastQueries);
    }

    /** A list «Departamento» with $leaves leaves; returns the root and the leaves. @return array{0: ListItem, 1: list<ListItem>} */
    private function list(int $leaves, string $name = 'Departamento'): array
    {
        $root = (new ListItem())->setEducationalCentre($this->centre)->setName($name);
        $this->persist($root);
        $all = [];
        for ($i = 0; $i < $leaves; ++$i) {
            $leaf  = (new ListItem())->setEducationalCentre($this->centre)->setName($name . ' ' . $i)->setPosition($i)->setParent($root);
            $all[] = $leaf;
            $this->persist($leaf);
        }

        return [$root, $all];
    }

    /**
     * @param list<ListItem> $roots one list-fed choice field per root
     *
     * @return array<string, mixed>
     */
    private function definitionFor(array $roots, string $type = 'choice'): array
    {
        $blocks = [];
        foreach ($roots as $i => $root) {
            $blocks[] = ['key' => FormDefinition::newKey(), 'type' => 'field', 'name' => 'c' . $i, 'fieldType' => $type, 'label' => 'Campo ' . $i, 'required' => false, 'options' => [], 'list' => $root->getId()->toRfc4122()];
        }

        return ['version' => 1, 'sections' => [['key' => FormDefinition::newKey(), 'title' => 'Datos', 'numbered' => true, 'help' => '', 'children' => [], 'blocks' => $blocks]]];
    }

    public function testExpandingListFedFieldsCostsTheSameWhateverTheirNumberOrTheValuesGone(): void
    {
        [$rootA] = $this->list(3, 'A');
        $roots   = [$rootA];
        for ($i = 0; $i < 7; ++$i) {
            $roots[] = $this->list(4, 'L' . $i)[0];
        }
        $this->flush();
        $lists  = self::getContainer()->get(FormListOptions::class);
        $centre = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());

        $small     = $this->definitionFor([$roots[0]]);
        $big       = $this->definitionFor($roots);
        $smallData = ['fields' => [$small['sections'][0]['blocks'][0]['key'] => FormDefinition::newKey()]];
        $bigData   = ['fields' => []];
        foreach ($big['sections'][0]['blocks'] as $block) {
            // A value that is no longer offered, in every field (UUID-shaped, so it is looked for among the centre's items).
            $bigData['fields'][$block['key']] = '0192a3b4-c5d6-7e8f-9a0b-' . substr(md5($block['key']), 0, 12);
        }

        $q1 = $this->queriesOf(static fn () => $lists->expand($small, $centre, $smallData));
        $q2 = $this->queriesOf(static fn () => $lists->expand($big, $centre, $bigData));
        self::assertGreaterThan(0, $q1, 'the query log is on');
        self::assertSame($q1, $q2, 'one read of the centre tree, however many fields and gone values');
        self::assertSame($q1, $this->queriesOf(static fn () => $lists->pickable($centre)), 'the editor\'s picker reads the same tree once too');
        self::assertSame($q1, $this->queriesOf(static fn () => $lists->emptySources($big, $centre)));
        self::assertSame($q1, $this->queriesOf(static fn () => $lists->toPaths($big, $centre)));
        $paths = $lists->toPaths($big, $centre);
        self::assertSame($q1, $this->queriesOf(static fn () => $lists->fromPaths($paths, $centre)));
    }

    public function testThePickerCostsTheSameForOneListAndForFifty(): void
    {
        $this->list(3, 'Uno');
        $this->flush();
        $lists = self::getContainer()->get(FormListOptions::class);
        $one   = $this->queriesOf(fn () => $lists->pickable($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId())));

        for ($i = 0; $i < 50; ++$i) {
            $this->list(2, 'Lista ' . $i);
        }
        $this->flush();
        $fifty = $this->queriesOf(fn () => $lists->pickable($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId())));
        self::assertSame($one, $fifty);
    }

    public function testTheSenecaPreviewCostsTheSameForAFewRemovalsAndForManyOfThem(): void
    {
        [$root] = $this->list(3, 'Materias');
        $this->flush();
        $importer = self::getContainer()->get(SenecaListImporter::class);
        $small    = $this->queriesOf(fn () => $importer->plan($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()), 'Materias', []));

        for ($i = 0; $i < 40; ++$i) {
            $leaf = (new ListItem())->setEducationalCentre($this->centre)->setName('Más ' . $i)->setPosition(10 + $i)->setParent($root);
            $this->persist($leaf);
        }
        $this->flush();
        $big = $this->queriesOf(fn () => $importer->plan($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()), 'Materias', []));

        self::assertSame($small, $big, "removing 43 items costs what removing 3 does:\n" . implode("\n", array_map(static fn (string $q): string => substr($q, 0, 90), $this->lastQueries)));
    }

    public function testWhatBlocksADeletionIsReadOncePerKindForTheWholeCentre(): void
    {
        $this->list(3, 'Pequeña');
        $this->flush();
        $usage  = self::getContainer()->get(\App\Service\ListItemUsage::class);
        $centre = fn (): EducationalCentre => $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $found  = $centre();
        $few    = $this->queriesOf(static fn () => $usage->keptByImports($found));
        self::assertSame(3, $few, 'profiles, assignments and documents: one query each');

        for ($i = 0; $i < 30; ++$i) {
            $this->list(5, 'Lista ' . $i);
        }
        $this->flush();
        $found = $centre();
        self::assertSame($few, $this->queriesOf(static fn () => $usage->keptByImports($found)));
        self::assertSame(2, $this->queriesOf(static fn () => $usage->blocking($found)));
    }

    public function testTheModelsScreenCostsTheSameForOneModelAndForTen(): void
    {
        $service = self::getContainer()->get(FormModelService::class);
        $make    = function (string $name) use ($service): void {
            // Requests clear the entity manager: always work with managed copies.
            $admin  = $this->em->getRepository(Teacher::class)->find($this->admin->getId());
            $folder = $this->em->getRepository(Folder::class)->find($this->folder->getId());
            $model  = $service->create($folder, $name, null);
            $service->saveDraft($model, $this->definitionFor([]) + [], $model->getDraftVersion(), $admin);
        };
        $make('Uno');
        $this->flush();
        $this->loginAs($this->admin, $this->centre);
        $one = $this->queriesOf(fn () => $this->client->request('GET', '/modelos'));

        for ($i = 0; $i < 9; ++$i) {
            $make('Modelo ' . $i);
        }
        $this->flush();
        $this->loginAs($this->admin, $this->centre);
        $ten = $this->queriesOf(fn () => $this->client->request('GET', '/modelos'));
        self::assertResponseIsSuccessful();
        self::assertSame($one, $ten);
    }

    private ?Activity $formActivity = null;
    private int $formCycle = 0;
    private int $delivered = 0;

    /** Adds $count delivered and approved submissions (one per slot) to the activity filled in online, creating it the first time. */
    private function deliver(int $count, int $leaves = 4, bool $multiple = false): void
    {
        $admin  = $this->em->getRepository(Teacher::class)->find($this->admin->getId());
        $folder = $this->em->getRepository(Folder::class)->find($this->folder->getId());
        if ($this->formActivity === null) {
            $service = self::getContainer()->get(FormModelService::class);
            $model   = $service->create($folder, 'Ficha', null);
            [$root]  = $this->list($leaves);
            $this->flush();
            $definition = $this->definitionFor([$this->em->getRepository(ListItem::class)->find($root->getId())], $multiple ? 'multichoice' : 'choice');
            $service->saveDraft($model, $definition, $model->getDraftVersion(), $admin);
            $service->sendToReview($model, $admin, null);

            $category = (new ActivityCategory())->setEducationalCentre($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()))->setName('Cat');
            $activity = (new Activity())->setCategory($category)->setTitle('Ficha')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder)->setFormModel($model);
            $this->persist($category, $activity);
            $this->formActivity = $activity;
            $this->formCycle    = self::getContainer()->get(ActivityDeadlineChecker::class)->currentCycleKey($activity);
        }

        $activity = $this->em->getRepository(Activity::class)->find($this->formActivity->getId());
        $model    = $activity->getFormModel();
        $force    = $this->em->getRepository(FormModelRevision::class)->findOneBy(['model' => $model]);
        $field    = FormDefinition::listOf($force->getDefinition()['sections'])[0]['blocks'][0]['key'];
        $leaf     = $this->em->getRepository(ListItem::class)->findOneBy(['name' => 'Departamento 0']);
        $chosen   = $leaf->getId()->toRfc4122();
        if ($multiple) {
            $chosen = array_map(static fn (ListItem $l): string => $l->getId()->toRfc4122(), $this->em->getRepository(ListItem::class)->findBy(['parent' => $leaf->getParent()], ['position' => 'ASC']));
        }
        $index    = self::getContainer()->get(FormValueIndexer::class);

        for ($i = 0; $i < $count; ++$i, ++$this->delivered) {
            $document = new Document($folder, 'Entrega ' . $this->delivered);
            $file     = new DocumentFile(hash('sha256', (string) $this->delivered), (string) $this->delivered, 'text/plain', 'f.txt', 1);
            $revision = new DocumentRevision($document, 1, $file, false, $admin);
            $this->persist($document, $file, $revision);
            $submission = new FormSubmission($activity, 'slot-' . $this->delivered, $this->formCycle, $force, []);
            $this->persist($submission);
            $filled = new FormSubmissionRevision($submission, $revision, $force, ['fields' => [$field => $chosen]]);
            $this->persist($filled);
            $this->flush();
            $index->index($filled);
            $this->flush();
        }
    }

    public function testTheExplorerAndTheLatestApprovedReadCostTheSameForTwoDeliveriesAndForTwelve(): void
    {
        $explorer   = self::getContainer()->get(FormDataExplorer::class);
        $repository = self::getContainer()->get(\App\Repository\FormSubmissionRevisionRepository::class);

        $this->deliver(2);
        $this->em->clear();
        $found = $this->em->getRepository(Activity::class)->find($this->formActivity->getId());
        $explorer->rows($found, $this->formCycle);   // warm-up: whatever the process reads once (not per delivery) is not what is measured
        $this->em->clear();
        $found = $this->em->getRepository(Activity::class)->find($this->formActivity->getId());
        $q2    = $this->queriesOf(fn () => $explorer->rows($found, $this->formCycle));
        self::assertGreaterThan(0, $q2);
        self::assertSame(1, $this->queriesOf(fn () => $repository->findLatestApprovedByActivityCycle($found, $this->formCycle)), 'the latest approved revision of every slot is ONE query');

        $this->deliver(10);
        $this->em->clear();
        $found = $this->em->getRepository(Activity::class)->find($this->formActivity->getId());
        $q12   = $this->queriesOf(fn () => $explorer->rows($found, $this->formCycle));
        self::assertSame($q2, $q12, "twelve deliveries cost what two do:\n" . implode("\n", $this->lastQueries));
        self::assertCount(12, $explorer->rows($found, $this->formCycle));
        self::assertSame(1, $this->queriesOf(fn () => $repository->findLatestApprovedByActivityCycle($found, $this->formCycle)));
    }

    public function testAMultipleChoiceIsIndexedWithOneRowPerOptionWhateverHowManyAreChosen(): void
    {
        $this->deliver(1, 40, true);
        $this->em->clear();
        $values = self::getContainer()->get(\App\Repository\FormValueRepository::class);
        $rows   = $values->findAll();
        self::assertCount(40, $rows, 'one row per option, none cut');
        $ids = array_map(static fn (\App\Entity\FormValue $v): string => (string) $v->getText(), $rows);
        self::assertCount(40, array_unique($ids));
        foreach ($ids as $id) {
            self::assertSame(36, \strlen($id), 'every key whole');
        }

        self::assertSame(1, $values->countDocumentsUsingItems($ids), 'a document that has 40 of the items is ONE document');
        self::assertCount(40, $values->findItemIdsInUse($ids));
        self::assertSame(1, $values->countDocumentsUsingItems([$ids[39]]), 'the last one chosen is found too (the joined value of 255 characters lost it)');

        // The explorer still reads them as one cell, with the names.
        $activity = $this->em->getRepository(Activity::class)->find($this->formActivity->getId());
        $out      = self::getContainer()->get(FormDataExplorer::class)->rows($activity, $this->formCycle);
        self::assertCount(1, $out);
        self::assertSame(40, \count(explode(', ', $out[0]['value'])));
    }

    /** A model in force with a list-fed choice, and an activity filled in online with it. */
    private function publishedActivity(string $name, ListItem $root): void
    {
        $service  = self::getContainer()->get(FormModelService::class);
        $admin    = $this->em->getRepository(Teacher::class)->find($this->admin->getId());
        $folder   = $this->em->getRepository(Folder::class)->find($this->folder->getId());
        $model    = $service->create($folder, $name, null);
        $service->saveDraft($model, $this->definitionFor([$this->em->getRepository(ListItem::class)->find($root->getId())]), $model->getDraftVersion(), $admin);
        $service->sendToReview($model, $admin, null);
        $category = (new ActivityCategory())->setEducationalCentre($this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()))->setName('Cat ' . $name);
        // An activity has a folder of its own.
        $delivery = (new Folder())->setDocumentSection($folder->getDocumentSection())->setName('Entregas ' . $name);
        $activity = (new Activity())->setCategory($category)->setTitle($name)->setStart(1, 9)->setEnd(30, 6)->setFolder($delivery)->setFormModel($model);
        $this->persist($category, $delivery, $activity);
    }

    public function testTheIndicatorFormsSourcesCostTheSameForOneFormActivityAndForSix(): void
    {
        [$root] = $this->list(3);
        $this->flush();
        $this->publishedActivity('Uno', $root);
        $choices = self::getContainer()->get(\App\Service\Form\FormSourceChoices::class);
        $centre  = fn (): EducationalCentre => $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId());
        $load    = static fn (EducationalCentre $c): array => $choices->forCentre($c);
        $load($centre());   // warm-up
        $found      = $centre();
        $one        = $this->queriesOf(static fn () => $load($found));
        $oneQueries = $this->lastQueries;

        for ($i = 0; $i < 5; ++$i) {
            $this->publishedActivity('Otra ' . $i, $root);
        }
        $this->em->clear();
        $found = $centre();
        $six   = $this->queriesOf(static fn () => $load($found));
        self::assertCount(6, $load($found));
        self::assertSame($one, $six, "six form activities cost what one does:\n--- one\n" . implode("\n", array_map(static fn (string $q): string => substr($q, 0, 90), $oneQueries)) . "\n--- six\n" . implode("\n", array_map(static fn (string $q): string => substr($q, 0, 90), $this->lastQueries)));
    }
}
