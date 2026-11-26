<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ActivityRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\ListItemRepository;
use App\Repository\TeacherRepository;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class LoadDemoDataCommandTest extends RepositoryTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command     = $application->find('app:load-demo-data');
        $this->tester = new CommandTester($command);
    }

    public function testProvisionsARichlyConnectedDemoCentre(): void
    {
        $this->tester->execute([]);

        self::assertSame(0, $this->tester->getStatusCode());

        $this->em->clear();

        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        $centre  = $centres->findByCode('29700456');
        self::assertNotNull($centre);
        self::assertSame('IES Ada Lovelace', $centre->getName());
        self::assertNotNull($centre->getActiveAcademicYear());

        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        // 4 named teachers (admin, calidad, dirección, multicentro) + the former teacher + 30 rank-and-file teachers.
        self::assertCount(35, $teachers->findAll());
        self::assertNotNull($teachers->findByUsername('admin'));
        self::assertNotNull($teachers->findByUsername('calidad'));
        self::assertNotNull($teachers->findByUsername('direccion'));

        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        // 7 ISO chapters + their subclauses.
        self::assertGreaterThan(7, count($sections->findAll()));

        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);
        // 3 core demo activities + 4 in "Seguimiento del SGC", one per status colour.
        self::assertCount(7, $activities->findAll());
        $titles = array_map(static fn ($a): string => $a->getTitle(), $activities->findAll());
        self::assertContains('Difusión de los objetivos de calidad', $titles);
        self::assertContains('Auditoría interna (planificación)', $titles);

        // "Mejora continua": one finding at each step, plus the nonconformity and the observation
        // of the audit whose report is out.
        /** @var \App\Repository\FindingRepository $findings */
        $findings = self::getContainer()->get(\App\Repository\FindingRepository::class);
        $counts = $findings->countByStatus($centre);
        ksort($counts);
        self::assertSame(['analysis' => 2, 'closed' => 1, 'execution' => 3, 'reported' => 1], $counts);

        // An approved programme of three audits.
        /** @var \App\Repository\AuditProgramRepository $programs */
        $programs = self::getContainer()->get(\App\Repository\AuditProgramRepository::class);
        $program  = $programs->findByYear($centre->getActiveAcademicYear() ?? throw new \LogicException());
        self::assertNotNull($program);
        self::assertTrue($program->isApproved());
        self::assertSame(['report_issued', 'in_progress', 'planned'], array_map(static fn ($a): string => $a->getStatus()->value, $program->getAudits()->toArray()));

        // ...and an improvement plan with an action done, one late and two pending.
        /** @var \App\Repository\ImprovementActionRepository $actions */
        $actions = self::getContainer()->get(\App\Repository\ImprovementActionRepository::class);
        $year    = $centre->getActiveAcademicYear();
        self::assertNotNull($year);
        self::assertCount(4, $actions->findPlan($centre, $year));
        self::assertCount(1, $actions->findPlan($centre, $year, ['status' => 'done']));
        self::assertCount(1, $actions->findPlan($centre, $year, ['status' => 'overdue']));

        // ...and five indicators, with last year's values and this year's first one off target.
        /** @var \App\Service\IndicatorBoardBuilder $board */
        $board = self::getContainer()->get(\App\Service\IndicatorBoardBuilder::class);
        $rows  = array_merge(...array_values($board->board($centre, $year)));
        self::assertCount(5, $rows);
        self::assertSame(['off_target'], array_values(array_unique(array_filter(array_map(static fn ($r): ?string => $r->status?->value, $rows)))));
        self::assertCount(5, array_filter($rows, static fn ($r): bool => $r->previous !== null));

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(5, $roots, 'the Departamento/Grupo/Materia roots CentreProvisioner creates must be reused, not duplicated, by the demo data, plus the nested Enseñanza list and Evaluación');
        self::assertSame(['Departamento', 'Grupo', 'Materia', 'Enseñanza', 'Evaluación'], array_map(static fn (ListItem $r): string => $r->getName(), $roots));

        // «Materia» is associated with «Departamento» (its subjects' departments) and with «Evaluación».
        self::assertSame(['Departamento', 'Evaluación'], array_map(static fn (ListItem $l): string => $l->getName(), $roots[2]->getLinkedLists()));
        $subjects = [];
        foreach ($items->findLeafDescendants($roots[2]) as $leaf) {
            $subjects[$leaf->getName()] = array_map(static fn (ListItem $l): string => $l->getName(), $leaf->getLinks());
        }
        self::assertSame(['Informática', '1ª', '2ª', 'Final 1', 'Final 2'], $subjects['Programación']);
        self::assertSame(['Matemáticas', '1ª', '2ª', 'Ord.', 'Extr.'], $subjects['Matemáticas']);
        self::assertSame(['Educación Física'], $subjects['Educación Física'], 'one of the two without an evaluation');
    }

    /**
     * The submissions every activity of the demo asks for, read with the names of what identifies them: they must not change when the
     * catalogue's associations change form. Taken before the associations with «perfil › subperfil» became associations with elements
     * of other lists (tests/Fixtures/demo-activity-slots.txt); a change to the demo that adds or removes submissions updates the file.
     */
    public function testEveryActivityOfTheDemoAsksForTheSameSubmissions(): void
    {
        $this->tester->execute([]);
        self::assertSame(0, $this->tester->getStatusCode());
        $this->em->clear();
        // The tree the command walked is cached with the entities of before the clear().
        self::getContainer()->get(ListItemRepository::class)->reset();

        $path = static function (?ListItem $item): string {
            $names = [];
            for ($n = $item; $n !== null; $n = $n->getParent()) {
                array_unshift($names, $n->getName());
            }

            return implode(' › ', $names);
        };
        /** @var \App\Service\ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(\App\Service\ActivitySubmissionSlotBuilder::class);
        $tuples  = [];
        foreach (self::getContainer()->get(ActivityRepository::class)->findAll() as $activity) {
            $prefix = $activity->getCategory()->getEducationalCentre()->getName() . ' / ' . $activity->getTitle();
            foreach ($builder->buildSlots($activity) as $slot) {
                $tuples[] = rtrim(implode(' | ', [$prefix, $slot->displayName, $slot->profile->getName(), $path($slot->listItem), $path($slot->nameListItem), $slot->teacher?->getUsername() ?? '', $slot->ownerLabel ?? '']));
            }
        }
        sort($tuples);
        $expected = file(dirname(__DIR__, 2) . '/Fixtures/demo-activity-slots.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($expected);
        $expected = array_map('rtrim', $expected);
        sort($expected);

        self::assertSame($expected, $tuples);
    }

    /** The extra fixtures the manual's screenshots rely on (see skills/screenshots.md). */
    public function testSeedsTheFixturesTheManualScreenshotsNeed(): void
    {
        $this->tester->execute([]);
        self::assertSame(0, $this->tester->getStatusCode());
        $this->em->clear();

        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        $centre  = $centres->findByCode('29700456');
        self::assertNotNull($centre);
        $year = $centre->getActiveAcademicYear();
        self::assertNotNull($year);

        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        $former   = $teachers->findByUsername('r.fuentes');
        self::assertNotNull($former);
        self::assertFalse($year->getTeachers()->contains($former), 'the former teacher is not in the course');

        $second = $centres->findByCode('18700123');
        self::assertNotNull($second);
        $multi = $teachers->findByUsername('multicentro');
        self::assertNotNull($multi);
        self::assertTrue($centres->isAccessibleByTeacher($centre, $multi));
        self::assertTrue($centres->isAccessibleByTeacher($second, $multi), 'multicentro belongs to both centres');
        $direccion = $teachers->findByUsername('direccion');
        self::assertNotNull($direccion);
        self::assertFalse($centres->isAccessibleByTeacher($second, $direccion), 'only multicentro sees the centre selection screen');

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $byName = [];
        foreach ($items->findAllByCentre($centre) as $item) {
            $top = $item;
            while ($top->getParent() !== null) {
                $top = $top->getParent();
            }
            if ($top->getName() === 'Enseñanza') {
                $byName[$item->getName()] = $item;
            }
        }
        self::assertFalse($byName['Programa de Mejora (extinguido)']->isActive());
        self::assertTrue($byName['2º DAW']->isAssociated(), 'an own association adds to the inherited one');
        self::assertTrue($byName['2º DAW']->hasInheritedAssociation());
        self::assertCount(2, $byName['2º DAW']->getEffectiveAssociations());
        self::assertFalse($byName['1º DAW']->isAssociated());
        self::assertTrue($byName['1º DAW']->hasInheritedAssociation(), 'the FP branch passes its association down');

        // The teachers of the computing modules (the catalogue's «Docentes», see skills/screenshots.md).
        $materias = [];
        foreach ($items->findAllByCentre($centre) as $item) {
            if ($item->getParent()?->getName() === 'Materia') {
                $materias[$item->getName()] = array_map(static fn ($t): string => $t->getUsername(), $item->getTeachers());
                sort($materias[$item->getName()]);
            }
        }
        self::assertSame(['n.bravo'], $materias['Programación']);
        self::assertSame(['m.pardo', 'n.bravo'], $materias['Bases de Datos'], 'a split group: two teachers');
        self::assertSame(['m.pardo'], $materias['Desarrollo Web en Entorno Cliente']);
        self::assertSame(['r.serrano'], $materias['Desarrollo Web en Entorno Servidor']);
        self::assertSame([], $materias['Sistemas Informáticos'], 'no teacher: «Sin docente asociado»');
        self::assertSame(['r.fuentes'], $materias['Fase de Formación en Empresa u Organismo Equiparado (FFEOE)'], 'whose teacher is out of the course');
        self::assertSame([], $materias['Matemáticas']);
    }

    public function testFailsWhenTheDemoCentreAlreadyExists(): void
    {
        $existing = (new EducationalCentre())->setCode('29700456')->setName('Ya existe')->setCity('Málaga');
        $this->persist($existing);

        $this->tester->execute([]);

        self::assertSame(1, $this->tester->getStatusCode());

        $this->em->clear();
        /** @var TeacherRepository $teachers */
        $teachers = self::getContainer()->get(TeacherRepository::class);
        self::assertCount(0, $teachers->findAll());
    }

    public function testFailsWithoutPartiallyCreatingAnythingWhenAPlannedUsernameIsAlreadyTaken(): void
    {
        $existing = (new Teacher(new PersonName('Ya', 'Existe')))->setUsername('admin');
        $this->persist($existing);

        $this->tester->execute([]);

        self::assertSame(1, $this->tester->getStatusCode());

        $this->em->clear();
        /** @var EducationalCentreRepository $centres */
        $centres = self::getContainer()->get(EducationalCentreRepository::class);
        self::assertNull($centres->findByCode('29700456'));
    }
}
