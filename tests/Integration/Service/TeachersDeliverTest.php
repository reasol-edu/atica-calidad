<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FormModel;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityLeafResolver;
use App\Service\ActivityObligationFinder;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\DocumentReviewOutcomeNotifier;
use App\Service\DocumentTreeAccessChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormModelService;
use App\Service\Form\FormSlotAccess;
use App\Service\SlotResponsibility;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The teachers associated with the elements of a list (phase 0b): in an activity whose box «Los docentes asociados a cada elemento
 * también lo entregan» is checked they fill in, deliver and own the delivery of their element; the head of department (who holds the
 * profile the element is owed by) can fill it in too and owns it only when the element has no teacher in the course.
 *
 * Materia: Programación (Noelia), Sistemas (nobody), FFEOE (Ricardo, who is not in the course) and Álgebra (another department,
 * Pablo's). Jefatura: Claudia heads Informática, Pablo heads Matemáticas.
 */
final class TeachersDeliverTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private EducationalCentre $centre;
    private Folder $modelFolder;
    private Folder $folder;
    private SpecificProfile $profile;
    private Activity $activity;
    private ListItem $subjects;
    private ListItem $informatica;
    private ListItem $matematicas;

    private Teacher $admin;
    private Teacher $claudia;
    private Teacher $pablo;
    private Teacher $noelia;
    private Teacher $ricardo;
    private Teacher $ana;

    /** @var array<string, ListItem> */
    private array $leaves = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year         = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($year);

        $mk = fn (string $first, string $user): Teacher => (new Teacher(new PersonName($first, 'Docente')))->setUsername($user);
        $this->admin   = $mk('Admin', 'admin')->setAdmin(true);
        $this->claudia = $mk('Claudia', 'claudia');
        $this->pablo   = $mk('Pablo', 'pablo');
        $this->noelia  = $mk('Noelia', 'noelia');
        $this->ricardo = $mk('Ricardo', 'ricardo');   // not in the course
        $this->ana     = $mk('Ana', 'ana');           // in the course, teaches nothing here
        foreach ([$this->admin, $this->claudia, $this->pablo, $this->noelia, $this->ana] as $teacher) {
            $teacher->addAcademicYear($year);
        }

        $departamento      = (new ListItem())->setEducationalCentre($this->centre)->setName('Departamento');
        $this->informatica = (new ListItem())->setEducationalCentre($this->centre)->setName('Informática')->setParent($departamento);
        $this->matematicas = (new ListItem())->setEducationalCentre($this->centre)->setName('Matemáticas')->setParent($departamento);
        $this->subjects    = (new ListItem())->setEducationalCentre($this->centre)->setName('Materia');
        $this->profile     = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefe/a de Departamento')->setListItem($departamento);
        $otherProfile      = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Equipo directivo');

        $this->persist(
            $this->centre, $year, $this->admin, $this->claudia, $this->pablo, $this->noelia, $this->ricardo, $this->ana,
            $departamento, $this->informatica, $this->matematicas, $this->subjects, $this->profile, $otherProfile,
        );
        foreach (['Programación' => $this->informatica, 'Sistemas' => $this->informatica, 'FFEOE' => $this->informatica, 'Álgebra' => $this->matematicas] as $name => $department) {
            $leaf = (new ListItem())->setEducationalCentre($this->centre)->setName($name)->setParent($this->subjects);
            $leaf->setAssociation($this->profile, $department);
            $this->leaves[$name] = $leaf;
            $this->em->persist($leaf);
        }
        $this->leaves['Programación']->addTeacher($this->noelia);
        $this->leaves['FFEOE']->addTeacher($this->ricardo);

        $section = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->modelFolder = (new Folder())->setDocumentSection($section)->setName('Modelos');
        $this->folder      = (new Folder())->setDocumentSection($section)->setName('Programaciones de los módulos');
        $this->folder->addUploadProfile($this->profile);
        // Visible only to the management team: nobody here can browse it, so what lets the teacher in is being the teacher.
        $this->folder->addVisibilityProfile($otherProfile);
        $category       = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $this->activity = (new Activity())->setCategory($category)->setTitle('Entrega de programaciones')->setStart(1, 9)->setEnd(31, 10)
            ->setFolder($this->folder)->setListItem($this->subjects);

        $this->persist(
            $section, $this->modelFolder, $this->folder, $category, $this->activity,
            new SpecificProfileAssignment($this->profile, $this->informatica, $this->claudia),
            new SpecificProfileAssignment($this->profile, $this->matematicas, $this->pablo),
        );
        $this->activity->setFormModel($this->modelInForce());
        $this->flush();
    }

    private function modelInForce(): FormModel
    {
        $service = self::getContainer()->get(FormModelService::class);
        $model   = $service->create($this->modelFolder, 'Programación del módulo', 'MD-08');
        $service->saveDraft($model, ['version' => 1, 'sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Programación', 'numbered' => true, 'help' => '', 'children' => [],
            'blocks' => [['key' => FormDefinition::newKey(), 'type' => 'field', 'fieldType' => 'short', 'label' => 'Objetivos', 'required' => true]],
        ]]], $model->getDraftVersion(), $this->admin);
        $service->sendToReview($model, $this->admin, null);

        return $model;
    }

    private function checkBox(bool $on): void
    {
        // A request detaches what the test persisted (the services are reset after it): work on the managed one.
        $activity = $this->em->find(Activity::class, $this->activity->getId());
        self::assertInstanceOf(Activity::class, $activity);
        $this->activity = $activity;
        $this->activity->setTeachersDeliver($on);
        $this->flush();
    }

    /** @return array<string, ActivitySubmissionSlot> by element name */
    private function slots(): array
    {
        $slots = [];
        foreach (self::getContainer()->get(ActivitySubmissionSlotBuilder::class)->buildSlots($this->activity) as $slot) {
            $slots[$slot->displayName] = $slot;
        }

        return $slots;
    }

    private function responsibility(): SlotResponsibility
    {
        return self::getContainer()->get(SlotResponsibility::class);
    }

    public function testTheBoxNeverCreatesNorRemovesSlotsNorChangesTheirKeys(): void
    {
        $before = array_map(static fn (ActivitySubmissionSlot $s): string => $s->key(), $this->slots());
        self::assertCount(4, $before);

        $this->checkBox(true);
        $after = array_map(static fn (ActivitySubmissionSlot $s): string => $s->key(), $this->slots());
        self::assertSame($before, $after, 'the slot key does not depend on the teachers: a draft stays put when a substitute arrives');

        // The substitute: another teacher in place of Noelia; the slot is the same one.
        $this->leaves['Programación']->removeTeacher($this->noelia)->addTeacher($this->ana);
        $this->flush();
        self::assertSame($before, array_map(static fn (ActivitySubmissionSlot $s): string => $s->key(), $this->slots()));
    }

    public function testWithTheBoxUncheckedTeachersChangeNothing(): void
    {
        $slots = $this->slots();
        $r     = $this->responsibility();

        self::assertFalse($r->canFill($this->noelia, $this->activity, $slots['Programación']));
        self::assertFalse($r->owns($this->noelia, $this->activity, $slots['Programación']));
        self::assertSame([], $r->teachersOf($this->activity, $slots['Programación']));
        // The head owns it, as ever, teacher or not.
        self::assertTrue($r->owns($this->claudia, $this->activity, $slots['Programación']));
        self::assertSame([], self::getContainer()->get(ActivityCompletionChecker::class)->getMySlots($this->noelia, $this->activity));
    }

    public function testWhoMayFillAndWhoseTaskItIs(): void
    {
        $this->checkBox(true);
        $slots = $this->slots();
        $r     = $this->responsibility();
        $act   = $this->activity;

        // A teacher of the element: fills it in and it is hers; the head of its department fills it in too but it is not hers.
        self::assertSame([$this->noelia], $r->teachersOf($act, $slots['Programación']));
        self::assertTrue($r->canFill($this->noelia, $act, $slots['Programación']));
        self::assertTrue($r->owns($this->noelia, $act, $slots['Programación']));
        self::assertTrue($r->canFill($this->claudia, $act, $slots['Programación']));
        self::assertFalse($r->owns($this->claudia, $act, $slots['Programación']));
        // Whoever manages the folder fills it in and never owns it.
        self::assertTrue($r->canFill($this->admin, $act, $slots['Programación']));
        self::assertFalse($r->owns($this->admin, $act, $slots['Programación']));
        // Anybody else: neither.
        self::assertFalse($r->canFill($this->ana, $act, $slots['Programación']));
        self::assertFalse($r->owns($this->ana, $act, $slots['Programación']));
        // The head of another department: neither.
        self::assertFalse($r->canFill($this->pablo, $act, $slots['Programación']));

        // The teacher of Programación has nothing to do with the other elements.
        self::assertFalse($r->canFill($this->noelia, $act, $slots['Sistemas']));
        self::assertFalse($r->owns($this->noelia, $act, $slots['Sistemas']));

        // Without teachers in the course (nobody, or only someone who left) the head owns it, as before.
        self::assertSame([], $r->teachersOf($act, $slots['Sistemas']));
        self::assertTrue($r->owns($this->claudia, $act, $slots['Sistemas']));
        self::assertSame([], $r->teachersOf($act, $slots['FFEOE']), 'Ricardo is not in the course');
        self::assertTrue($r->owns($this->claudia, $act, $slots['FFEOE']));
        self::assertFalse($r->canFill($this->ricardo, $act, $slots['FFEOE']));
        self::assertFalse($r->owns($this->ricardo, $act, $slots['FFEOE']));
        self::assertFalse(self::getContainer()->get(FormSlotAccess::class)->canFill($this->ricardo, $act, $slots['FFEOE']));

        // An inactive teacher does not count either.
        $this->noelia->setActive(false);
        $this->flush();
        self::assertSame([], $this->responsibility()->teachersOf($act, $this->slots()['Programación']));
        self::assertTrue($this->responsibility()->owns($this->claudia, $act, $this->slots()['Programación']));
    }

    public function testAskingForTheTeachersOfEverySlotQueriesTheCourseOnce(): void
    {
        $this->checkBox(true);
        $slots = $this->slots();

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        foreach ([...$slots, ...$slots] as $slot) {
            $this->responsibility()->teachersOf($this->activity, $slot);
            $this->responsibility()->owns($this->claudia, $this->activity, $slot);
        }

        $academicYearQueries = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $q): bool => str_contains((string) $q['sql'], 'teacher_academic_year'),
        );
        self::assertCount(1, $academicYearQueries, 'who is in the course is read once, not once per slot');
    }

    public function testATeacherInheritsTheTeachersOfTheirAncestors(): void
    {
        $this->checkBox(true);
        // Ana teaches the whole list: she teaches every element, including Sistemas.
        $this->subjects->addTeacher($this->ana);
        $this->flush();

        $slots = $this->slots();
        self::assertTrue($this->responsibility()->owns($this->ana, $this->activity, $slots['Sistemas']));
        self::assertFalse($this->responsibility()->owns($this->claudia, $this->activity, $slots['Sistemas']), 'now it has a teacher');
        self::assertTrue($this->responsibility()->canFill($this->claudia, $this->activity, $slots['Sistemas']));
    }

    public function testMyActivitiesAndTheManagedDeliveries(): void
    {
        $this->checkBox(true);
        $completion = self::getContainer()->get(ActivityCompletionChecker::class);
        $names      = static fn (array $slots): array => array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName, $slots);

        self::assertSame(['Programación'], $names($completion->getMyOwnedSlots($this->noelia, $this->activity)));
        self::assertSame(['Programación'], $names($completion->getMySlots($this->noelia, $this->activity)));

        // Claudia owns what has no teacher in the course and may act on the rest of her department's, but not on Matemáticas'.
        self::assertSame(['Sistemas', 'FFEOE'], $names($completion->getMyOwnedSlots($this->claudia, $this->activity)));
        self::assertSame(['Programación', 'Sistemas', 'FFEOE'], $names($completion->getMySlots($this->claudia, $this->activity)));
        self::assertSame(['Álgebra'], $names($completion->getMyOwnedSlots($this->pablo, $this->activity)));

        // The obligations of «Mis actividades», the bell and the reminders come from the owned slots.
        $finder = self::getContainer()->get(ActivityObligationFinder::class);
        self::assertCount(1, $finder->forActivity($this->noelia, $this->activity));
        self::assertCount(2, $finder->forActivity($this->claudia, $this->activity));
        self::assertCount(1, $finder->forActivity($this->pablo, $this->activity));
        self::assertSame([], $finder->forActivity($this->ana, $this->activity));
        self::assertSame([], $finder->forActivity($this->ricardo, $this->activity));
    }

    public function testTheFormOfTheElementIsTheirs(): void
    {
        $this->checkBox(true);
        $slots = $this->slots();
        $url   = static fn (ActivitySubmissionSlot $slot, Activity $a): string => '/actividades/' . $a->getId()->toRfc4122() . '/formulario/' . $slot->key();

        $this->loginAs($this->noelia, $this->centre);
        $this->client->request('GET', $url($slots['Programación'], $this->activity));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the teacher of the element opens its form');
        $this->client->request('GET', $url($slots['Sistemas'], $this->activity));
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'and not the one of an element that is not hers');

        $this->loginAs($this->claudia, $this->centre);
        $this->client->request('GET', $url($slots['Programación'], $this->activity));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the head of the department fills in the one of a teacher of hers');

        // Unchecked: the teacher is just another teacher.
        $this->checkBox(false);
        $this->loginAs($this->noelia, $this->centre);
        $this->client->request('GET', $url($slots['Programación'], $this->activity));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** Changing the teacher in the catalogue leaves the draft where it is: the substitute opens it as it was. */
    public function testASubstituteOpensTheDraftOfTheTeacherWhoLeft(): void
    {
        $this->checkBox(true);
        $slot = $this->slots()['Programación'];
        $url  = '/actividades/' . $this->activity->getId()->toRfc4122() . '/formulario/' . $slot->key();

        $this->loginAs($this->noelia, $this->centre);
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $before = $this->em->getRepository(\App\Entity\FormSubmission::class)->findAll();
        self::assertCount(1, $before);
        $draftId = $before[0]->getId()->toRfc4122();

        // Ana takes Noelia's place in the catalogue.
        $leaf = $this->em->find(ListItem::class, $this->leaves['Programación']->getId());
        self::assertNotNull($leaf);
        foreach ($leaf->getTeachers() as $teacher) {
            $leaf->removeTeacher($teacher);
        }
        $leaf->addTeacher($this->em->find(Teacher::class, $this->ana->getId()) ?? $this->ana);
        $this->flush();

        $this->loginAs($this->ana, $this->centre);
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the substitute opens it');
        $after = $this->em->getRepository(\App\Entity\FormSubmission::class)->findAll();
        self::assertCount(1, $after, 'no second draft');
        self::assertSame($draftId, $after[0]->getId()->toRfc4122());

        $this->loginAs($this->noelia, $this->centre);
        $this->client->request('GET', $url);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'and the one who left no longer does');
    }

    public function testTheActivityListsTheirNamesUnderEachDelivery(): void
    {
        $this->checkBox(true);
        $this->loginAs($this->claudia, $this->centre);
        $activityId = $this->activity->getId()->toRfc4122();
        $component  = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $this->centre,
            'initialCategoryId' => $this->activity->getCategory()->getId()->toRfc4122(),
        ], $this->client);
        $component->call('toggleActivity', ['activityId' => $activityId])->call('toggleAllSubmissions', ['activityId' => $activityId]);
        $html = (string) $component->render();

        // Hers (no teacher in the course) with the amber note; the rest, apart, with their teachers' names.
        self::assertStringContainsString('Entregas que gestionas', $html, 'the head sees the ones that have a teacher apart');
        self::assertStringContainsString('Noelia Docente', $html);
        self::assertStringContainsString('Sin docente asociado', $html);
        self::assertStringNotContainsString('Ricardo Docente', $html, 'whoever left the course is not named');

        // Unchecked, nothing about teachers shows.
        $this->checkBox(false);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $this->centre,
            'initialCategoryId' => $this->activity->getCategory()->getId()->toRfc4122(),
        ], $this->client);
        $component->call('toggleActivity', ['activityId' => $activityId])->call('toggleAllSubmissions', ['activityId' => $activityId]);
        $html = (string) $component->render();
        self::assertStringNotContainsString('Noelia Docente', $html);
        self::assertStringNotContainsString('Sin docente asociado', $html);
    }

    public function testDocumentAccessForTheTeacherOfTheElement(): void
    {
        $slots    = $this->slots();
        $slot     = $slots['Programación'];
        $document = new Document($this->folder, 'Programación');
        $document->setUploadProfile($slot->profile, $slot->listItem);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $this->ana);
        $this->persist($document, $file, $revision);

        $access  = self::getContainer()->get(DocumentTreeAccessChecker::class);
        $noelia  = $this->noelia;
        $ricardo = $this->ricardo;

        // The box unchecked: the folder is restricted and she does not hold the delivery's profile.
        self::assertFalse($access->canViewDocument($noelia, $document));
        self::assertFalse($access->canManageDocumentAsUploader($noelia, $document));
        self::assertFalse($access->canOpenDocument($noelia, $document));

        $this->checkBox(true);

        self::assertTrue($access->canViewDocument($noelia, $document));
        self::assertTrue($access->canManageDocumentAsUploader($noelia, $document));
        // She can open it (download it, see the delivered form and its annexes: DocumentVoter::VIEW) though not its folder,
        // so the folder's ZIP stays closed to her.
        self::assertTrue($access->canOpenDocument($noelia, $document));
        self::assertFalse($access->canViewFolder($noelia, $document->getFolder()));
        // Only the delivery of her own element: the teacher of another one is left out.
        $other = new Document($document->getFolder(), 'Sistemas');
        $other->setUploadProfile($document->getUploadProfile(), $document->getUploadListItem());
        $this->em->persist($other);
        $this->em->flush();
        self::assertFalse($access->canViewDocument($noelia, $other));
        self::assertFalse($access->canOpenDocument($noelia, $other));
        // And whoever left the course cannot open the one of their former element.
        $ffeoe = new Document($document->getFolder(), 'FFEOE');
        $ffeoe->setUploadProfile($document->getUploadProfile(), $document->getUploadListItem());
        $this->em->persist($ffeoe);
        $this->em->flush();
        self::assertFalse($access->canViewDocument($ricardo, $ffeoe));
        self::assertFalse($access->canManageDocumentAsUploader($ricardo, $ffeoe));
    }

    public function testTheLeafResolverGivesTheLeafTheSlotBuilderDid(): void
    {
        $resolver = self::getContainer()->get(ActivityLeafResolver::class);
        $document = new Document($this->folder, 'Sistemas');
        self::assertSame($this->leaves['Sistemas'], $resolver->findLeafForDocument($this->activity, $document));
        self::assertNull($resolver->findLeafForDocument($this->activity, new Document($this->folder, 'Inventada')));

        $builder = self::getContainer()->get(ActivitySubmissionSlotBuilder::class);
        self::assertSame('Álgebra', $resolver->submissionName($this->subjects, $this->leaves['Álgebra']));
        self::assertSame($builder->submissionName($this->subjects, $this->leaves['Álgebra']), $resolver->submissionName($this->subjects, $this->leaves['Álgebra']));

        // A second «Sistemas» appears in the catalogue after the activity was saved: the name names neither, rather than guessing
        // (the first one's teachers would open the other's document).
        $twin = (new \App\Entity\ListItem())->setEducationalCentre($this->subjects->getEducationalCentre())->setName('Sistemas')->setParent($this->subjects)->setPosition(99);
        $this->persist($twin);
        $resolver->reset();
        self::getContainer()->get(\App\Repository\ListItemRepository::class)->reset();
        self::assertNull($resolver->findLeafForDocument($this->activity, $document));
    }

    public function testTheOutcomeGoesToWhoeverTheTaskBelongsTo(): void
    {
        $this->checkBox(true);
        $slot     = $this->slots()['Programación'];
        $document = new Document($this->folder, 'Programación');
        $document->setUploadProfile($slot->profile, $slot->listItem);
        $file     = new DocumentFile(hash('sha256', 'y'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $this->ana);
        $this->persist($document, $file, $revision);

        $recipients = self::getContainer()->get(DocumentReviewOutcomeNotifier::class)->recipientsFor($this->folder, $revision);
        $usernames  = array_map(static fn (Teacher $t): string => $t->getUsername(), $recipients);
        sort($usernames);

        // The element has a teacher in the course: the task is Noelia's, so only she hears. Not Claudia (who holds the row and only
        // oversees it now), not Ana (who delivered it on someone's behalf), not Pablo (another department).
        self::assertSame(['noelia'], $usernames);

        // Without the box the task is the row's again: its holder, and still not the other department nor whoever delivered it.
        $this->checkBox(false);
        $usernames = array_map(static fn (Teacher $t): string => $t->getUsername(), self::getContainer()->get(DocumentReviewOutcomeNotifier::class)->recipientsFor($this->folder, $revision));
        sort($usernames);
        self::assertSame(['claudia'], $usernames);
    }
}
