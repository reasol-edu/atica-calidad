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
        // 3 named teachers (admin, calidad, dirección) + 30 rank-and-file teachers.
        self::assertCount(33, $teachers->findAll());
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
        self::assertCount(3, $roots, 'the Departamento/Grupo/Materia roots CentreProvisioner creates must be reused, not duplicated, by the demo data');
        self::assertSame(['Departamento', 'Grupo', 'Materia'], array_map(static fn (ListItem $r): string => $r->getName(), $roots));
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
