<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingSeverity;
use App\Entity\ImprovementActionType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Model\QualityTask;
use App\Service\FindingService;
use App\Service\QualityTaskFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * What each teacher has to do in "Mejora continua" — the list behind "Tus próximos pasos", the bell,
 * the calendar and the daily reminder. Nobody is logged in: the reminder runs like that, so the
 * managers' own tasks must still show up.
 */
final class QualityTaskFinderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $teacher;
    private Teacher $other;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-05 10:00:00');

        $this->centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year    = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->manager = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad');
        $this->teacher = (new Teacher(new PersonName('Luis', 'Docente')))->setUsername('docente');
        $this->other   = (new Teacher(new PersonName('Olga', 'Otra')))->setUsername('otra');
        $this->centre->addQualityManager($this->manager);
        $this->persist($this->centre, $this->year, $this->manager, $this->teacher, $this->other);
    }

    private function service(): FindingService
    {
        /** @var FindingService $service */
        $service = self::getContainer()->get(FindingService::class);

        return $service;
    }

    private function finder(): QualityTaskFinder
    {
        /** @var QualityTaskFinder $finder */
        $finder = self::getContainer()->get(QualityTaskFinder::class);

        return $finder;
    }

    /** @return list<string> "type:label (urgency)" */
    private static function describe(array $tasks): array
    {
        return array_map(static fn (QualityTask $t): string => $t->type . ':' . $t->label() . ' (' . $t->urgency . ')', $tasks);
    }

    private function planAction(string $description, Teacher $responsible, string $due): void
    {
        $this->service()->createPlanAction($this->centre, $this->year, $this->manager, ImprovementActionType::Improvement, $description, null, null, $responsible, null, new \DateTimeImmutable($due));
    }

    public function testAPlanActionIsATaskOfItsResponsibleOnly(): void
    {
        $this->planAction('Guía de acogida', $this->teacher, '2026-10-08');

        $tasks = $this->finder()->forTeacher($this->teacher, $this->centre);
        self::assertSame(['action:Guía de acogida (soon)'], self::describe($tasks));
        self::assertNull($tasks[0]->finding);
        self::assertMatchesRegularExpression('/^PM-2026-\d{3}$/', (string) $tasks[0]->code());

        self::assertSame([], $this->finder()->forTeacher($this->other, $this->centre));
        // The manager created it, but doesn't have to do it.
        self::assertSame([], $this->finder()->forTeacher($this->manager, $this->centre));
    }

    public function testTheManagersTasksWithNobodyLoggedIn(): void
    {
        $finding = $this->service()->report($this->centre, $this->teacher, 'El proyector no funciona', null);

        self::assertSame(['classify:El proyector no funciona (open)'], self::describe($this->finder()->forTeacher($this->manager, $this->centre)));
        self::assertSame([], $this->finder()->forTeacher($this->teacher, $this->centre));

        $this->service()->classify($finding, $this->manager, FindingKind::Nonconformity, FindingSeverity::Minor, 'Proyector', null, FindingOrigin::InternalReport, $this->teacher, new \DateTimeImmutable('2026-10-01'));
        self::assertSame(['analyze:Proyector (overdue)'], self::describe($this->finder()->forTeacher($this->teacher, $this->centre)));
    }

    public function testTheCalendarAlsoShowsWhatsDone(): void
    {
        $this->planAction('Hecha', $this->teacher, '2026-10-10');
        $this->planAction('Pendiente', $this->teacher, '2026-10-20');
        $this->planAction('De otro mes', $this->teacher, '2026-12-01');
        $done = $this->finder()->forTeacher($this->teacher, $this->centre);
        $this->service()->completeAction($done[0]->action ?? throw new \LogicException(), $this->teacher, 'Hecho');

        self::assertSame(
            ['action:Pendiente (open)', 'action:Hecha (done)'],
            self::describe($this->finder()->dueBetween($this->teacher, $this->centre, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))),
        );
        self::assertSame(
            ['action:Hecha (done)'],
            self::describe($this->finder()->dueBetween($this->teacher, $this->centre, new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-10'))),
        );
    }
}
