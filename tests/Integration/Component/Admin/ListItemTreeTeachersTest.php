<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The teachers associated with the elements of a list, in the catalogue editor (phase 0b): the panel's «Docentes» picker, what is
 * inherited, the badge of the tree, the bulk actions, the «Sin docente» filter and «Quitar docentes fuera de curso».
 *
 * Materia ─ Programación (Noelia) ─ 1º DAM, 1º DAW (Marcos)
 *        └─ Sistemas (nobody)
 *        └─ FFEOE (Ricardo, who is not in the course)
 */
final class ListItemTreeTeachersTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private EducationalCentre $centre;
    private Teacher $admin;
    private Teacher $noelia;
    private Teacher $marcos;
    private Teacher $ricardo;
    private ListItem $root;
    private ListItem $programacion;
    private ListItem $dam;
    private ListItem $daw;
    private ListItem $sistemas;
    private ListItem $ffeoe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year         = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($year);

        $mk = static fn (string $first, string $user): Teacher => (new Teacher(new PersonName($first, 'Docente')))->setUsername($user);
        $this->admin   = $mk('Admin', 'director');
        $this->noelia  = $mk('Noelia', 'n.bravo');
        $this->marcos  = $mk('Marcos', 'm.pardo');
        $this->ricardo = $mk('Ricardo', 'r.fuentes');
        $this->centre->getAdmins()->add($this->admin);
        foreach ([$this->admin, $this->noelia, $this->marcos] as $teacher) {
            $teacher->addAcademicYear($year);
        }

        $this->root         = (new ListItem())->setEducationalCentre($this->centre)->setName('Materia');
        $this->programacion = (new ListItem())->setEducationalCentre($this->centre)->setName('Programación')->setParent($this->root);
        $this->dam          = (new ListItem())->setEducationalCentre($this->centre)->setName('1º DAM')->setParent($this->programacion);
        $this->daw          = (new ListItem())->setEducationalCentre($this->centre)->setName('1º DAW')->setParent($this->programacion);
        $this->sistemas     = (new ListItem())->setEducationalCentre($this->centre)->setName('Sistemas')->setParent($this->root);
        $this->ffeoe        = (new ListItem())->setEducationalCentre($this->centre)->setName('FFEOE')->setParent($this->root);
        $this->programacion->addTeacher($this->noelia);
        $this->daw->addTeacher($this->marcos);
        $this->ffeoe->addTeacher($this->ricardo);

        $this->persist(
            $this->centre, $year, $this->admin, $this->noelia, $this->marcos, $this->ricardo,
            $this->root, $this->programacion, $this->dam, $this->daw, $this->sistemas, $this->ffeoe,
        );
    }

    private function component(): TestLiveComponent
    {
        $this->loginAs($this->admin, $this->centre);

        return $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
    }

    private function id(object $entity): string
    {
        /** @var ListItem|Teacher $entity */
        return $entity->getId()->toRfc4122();
    }

    /** @return list<string> usernames */
    private function ownTeachers(ListItem $item): array
    {
        // What is in the table, not what the entities in memory say.
        $names = array_map(
            static fn (array $row): string => (string) $row['username'],
            $this->em->createQuery('SELECT t.username FROM App\Entity\ListItemTeacher lt JOIN lt.teacher t WHERE lt.listItem = :item')
                ->setParameter('item', $item->getId(), 'uuid')
                ->getArrayResult(),
        );
        sort($names);

        return array_values($names);
    }

    public function testThePanelOpensWithTheOwnTeachersAndSaysWhatItInherits(): void
    {
        $component = $this->component();
        $component->call('selectItem', ['id' => $this->id($this->daw)]);
        $crawler = $component->render()->crawler();

        self::assertSame([$this->id($this->marcos)], $this->propsOf($component)['teacherIds'], 'the picker holds the own ones only');
        self::assertCount(1, $crawler->filter('#teachers-' . $this->id($this->daw) . '[data-live-ignore]'), 'the picker is an own island with a stable id per item');
        self::assertStringContainsString('Además, de «Programación»: Noelia Docente', $crawler->filter('[data-testid="teachers-inherited"]')->text());
        // The people of the course are the options.
        self::assertStringContainsString('Marcos Docente', $crawler->filter('#teachers-' . $this->id($this->daw))->text());
        self::assertStringNotContainsString('Ricardo Docente', $crawler->filter('#teachers-' . $this->id($this->daw))->text(), 'who left the course is no option');
    }

    public function testSaveTeachersReplacesTheOwnOnesAndDropsWhatIsNotInTheCourse(): void
    {
        $component = $this->component();
        $component->call('selectItem', ['id' => $this->id($this->sistemas)]);
        $component->set('teacherIds', [$this->id($this->noelia), $this->id($this->ricardo), 'not-an-id'])->call('saveTeachers');

        self::assertSame(['n.bravo'], $this->ownTeachers($this->sistemas), 'a teacher of no course, or an invented id, is not associated');
    }

    public function testSavingKeepsAnOwnTeacherWhoLeftTheCourseSoNothingIsLostBySilence(): void
    {
        $component = $this->component();
        $component->call('selectItem', ['id' => $this->id($this->ffeoe)]);
        $crawler = $component->render()->crawler();

        self::assertStringContainsString('Ricardo Docente', $crawler->filter('#teachers-' . $this->id($this->ffeoe))->text(), 'offered, so that saving does not drop them');
        self::assertStringContainsString('Fuera de curso', $crawler->filter('[data-testid="teachers-out-of-course"]')->text());

        $component->set('teacherIds', [$this->id($this->ricardo), $this->id($this->marcos)])->call('saveTeachers');
        self::assertSame(['m.pardo', 'r.fuentes'], $this->ownTeachers($this->ffeoe));
    }

    public function testTheTreeBadgeNamesOneTeacherCountsSeveralAndIsPalerWhenInherited(): void
    {
        $component = $this->component();
        $html      = (string) $component->render()->crawler()->html();
        $crawler   = $component->render()->crawler();

        $badge = static fn (string $id) => $crawler->filter('[data-list-item-id="' . $id . '"] > div [data-testid="teacher-badge"]');
        self::assertStringContainsString('Noelia Docente', $badge($this->id($this->programacion))->text());
        self::assertStringNotContainsString('bg-gray-50', (string) $badge($this->id($this->programacion))->attr('class'), 'own: the normal badge');
        // 1º DAW has Marcos of its own and inherits Noelia: two, counted.
        self::assertSame('2', trim($badge($this->id($this->daw))->text()));
        self::assertStringContainsString('Marcos Docente, Noelia Docente', (string) $badge($this->id($this->daw))->attr('title'));
        // 1º DAM only inherits: paler, with the name of who teaches it.
        self::assertStringContainsString('bg-gray-50', (string) $badge($this->id($this->dam))->attr('class'));
        self::assertStringContainsString('Noelia Docente', $badge($this->id($this->dam))->text());
        self::assertCount(0, $badge($this->id($this->sistemas)), 'nobody, no badge');
        self::assertStringContainsString('fuera de curso', (string) $badge($this->id($this->ffeoe))->attr('title'));
        self::assertStringContainsString('bg-red-50', (string) $badge($this->id($this->ffeoe))->attr('class'), 'red when nobody in the badge is in the course, like in Asignar perfiles');
        self::assertNotSame('', $html);
    }

    public function testBulkAddRemoveAndReplaceTheTeachersOfTheCheckedItems(): void
    {
        $component = $this->component();
        $component->call('toggleBulkSelectMode');
        $checked = [$this->id($this->sistemas), $this->id($this->dam)];

        // Add: they are added to what each has.
        $component->set('bulkSelectedIds', $checked)->set('bulkTeacherIds', [$this->id($this->marcos)])->set('bulkTeacherMode', 'add');
        self::assertSame('Se añadirá a Marcos Docente en 2 elementos.', $component->component()->getBulkTeacherSummary());
        $component->call('bulkApplyTeachers');
        self::assertSame(['m.pardo'], $this->ownTeachers($this->sistemas));
        self::assertSame(['m.pardo'], $this->ownTeachers($this->dam));

        // Remove (leaving bulk mode and entering it again forgets what was checked).
        $component->call('toggleBulkSelectMode')->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->sistemas)])->set('bulkTeacherIds', [$this->id($this->marcos)])->set('bulkTeacherMode', 'remove')->call('bulkApplyTeachers');
        self::assertSame([], $this->ownTeachers($this->sistemas));
        self::assertSame(['m.pardo'], $this->ownTeachers($this->dam), 'the others are not touched');

        // Replace: what each had goes, only the picked ones stay.
        $component->call('toggleBulkSelectMode')->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->dam), $this->id($this->daw)])->set('bulkTeacherIds', [$this->id($this->noelia)])->set('bulkTeacherMode', 'replace')->call('bulkApplyTeachers');
        self::assertSame(['n.bravo'], $this->ownTeachers($this->dam));
        self::assertSame(['n.bravo'], $this->ownTeachers($this->daw));
    }

    public function testTheSummaryIsInTheSingularForOneItem(): void
    {
        $component = $this->component();
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->sistemas)])->set('bulkTeacherIds', [$this->id($this->noelia)]);

        self::assertSame('Se añadirá a Noelia Docente en 1 elemento.', $component->component()->getBulkTeacherSummary());
        $component->set('bulkTeacherMode', 'replace');
        self::assertSame('Los docentes propios de 1 elemento pasarán a ser: Noelia Docente.', $component->component()->getBulkTeacherSummary());
    }

    /** A branch that would inherit other teachers once moved (who delivers in the activities that use them) asks for confirmation first. */
    public function testMovingABranchAsksForConfirmationWhenTheTeachersItInheritsChange(): void
    {
        $component = $this->component();
        $component->call('toggleBulkSelectMode');
        // 1º DAM inherits Noelia from «Programación»: under the root it would not.
        $component->set('bulkSelectedIds', [$this->id($this->dam)])->set('bulkMoveTargetId', $this->id($this->root))->call('bulkMoveSelected');
        self::assertNotSame('', $this->propsOf($component)['bulkMoveConfirmKey'], 'it loses Noelia: confirmation requested');
        self::assertStringContainsString('docentes asociados', $component->render()->crawler()->filter('.js-list-item-tree')->text());

        // Cancelled, nothing moved.
        $component->call('cancelBulkMove');
        $parent = $this->em->createQuery('SELECT IDENTITY(li.parent) FROM App\Entity\ListItem li WHERE li.id = :id')->setParameter('id', $this->dam->getId(), 'uuid')->getSingleScalarResult();
        self::assertNotNull($parent);

        // A leaf that has no teacher above it and is moved under another with none does not ask.
        $component->set('bulkSelectedIds', [$this->sistemas->getId()->toRfc4122()])->set('bulkMoveTargetId', $this->id($this->root))->call('bulkMoveSelected');
        self::assertSame('', $this->propsOf($component)['bulkMoveConfirmKey'], 'same chain of teachers: it just moves');
    }

    public function testBulkActionsDoNothingWithNothingCheckedOrPicked(): void
    {
        $component = $this->component();
        $component->call('toggleBulkSelectMode');
        $component->set('bulkTeacherIds', [$this->id($this->noelia)])->call('bulkApplyTeachers');
        self::assertSame([], $this->ownTeachers($this->sistemas));
        self::assertNull($component->component()->getBulkTeacherSummary(), 'nothing checked, nothing to say');

        $component->call('toggleBulkSelectMode')->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->sistemas)])->set('bulkTeacherMode', 'replace')->call('bulkApplyTeachers');
        self::assertSame([], $this->ownTeachers($this->sistemas), 'replacing with nobody picked does not wipe anything');
    }

    public function testTheWithoutTeacherFilterKeepsOnlyTheLeavesNobodyInTheCourseTeaches(): void
    {
        $component = $this->component();
        $component->call('toggleOnlyWithoutTeacher');
        $html = (string) $component->render()->crawler()->filter('.js-list-item-tree .hidden.lg\\:block')->html();

        // Sistemas (nobody) and FFEOE (only someone who left) are missing a teacher; Programación's leaves are not.
        self::assertStringContainsString('data-list-item-id="' . $this->id($this->sistemas) . '"', $html);
        self::assertStringContainsString('data-list-item-id="' . $this->id($this->ffeoe) . '"', $html);
        self::assertStringNotContainsString('data-list-item-id="' . $this->id($this->dam) . '"', $html);
        self::assertStringNotContainsString('data-list-item-id="' . $this->id($this->daw) . '"', $html);
        self::assertStringNotContainsString('data-list-item-id="' . $this->id($this->programacion) . '"', $html, 'a branch only shows when something below it is missing');

        $component->call('toggleOnlyWithoutTeacher');
        self::assertStringContainsString('data-list-item-id="' . $this->id($this->dam) . '"', (string) $component->render()->crawler()->filter('.js-list-item-tree .hidden.lg\\:block')->html());
    }

    public function testRemovingTheTeachersWhoAreOutOfTheCourseAsksFirstAndCountsThem(): void
    {
        $component = $this->component();
        $crawler   = $component->render()->crawler();
        self::assertStringContainsString('Quitar docentes fuera de curso (1)', $crawler->filter('[data-testid="off-course-button"]')->first()->text());
        self::assertSame(1, $component->component()->getOffCourseTeacherCount());

        $component->call('askRemoveOffCourseTeachers');
        self::assertCount(2, $component->render()->crawler()->filter('[data-testid="off-course-confirm"]'), 'once per header (desktop and mobile)');
        self::assertSame(['r.fuentes'], $this->ownTeachers($this->ffeoe), 'nothing is removed until confirmed');

        $component->call('removeOffCourseTeachers');
        self::assertSame([], $this->ownTeachers($this->ffeoe));
        self::assertSame(['n.bravo'], $this->ownTeachers($this->programacion), 'the ones in the course stay');
        self::assertSame(0, $component->component()->getOffCourseTeacherCount());
        self::assertCount(0, $component->render()->crawler()->filter('[data-testid="off-course-button"]'));
    }

    /** @return array<string, mixed> */
    private function propsOf(TestLiveComponent $component): array
    {
        $value = $component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertNotNull($value);

        /** @var array<string, mixed> $props */
        $props = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return $props;
    }
}
