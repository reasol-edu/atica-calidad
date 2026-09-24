<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\FindingOrigin;
use App\Entity\FindingStatus;
use App\Entity\Indicator;
use App\Entity\IndicatorStatus;
use App\Entity\MeasurementCalendar;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Model\QualityTask;
use App\Repository\EmailNotificationLogRepository;
use App\Service\IndicatorBoardBuilder;
use App\Service\IndicatorService;
use App\Service\QualityTaskFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Indicators through IndicatorService: calendars, values and what's made of one off target, the
 * copy into a new year, and the tasks all that gives (QualityTaskFinder). Nobody is logged in.
 */
final class IndicatorServiceTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private AcademicYear $previous;
    private AcademicYear $year;
    private Teacher $manager;
    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-28 10:00:00');

        $this->centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->previous = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($this->centre);
        $this->year     = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $this->manager = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad')->setEmail('calidad@example.com');
        $this->teacher = (new Teacher(new PersonName('Ana', 'Ruiz')))->setUsername('ana');
        $this->centre->addQualityManager($this->manager);
        $emailOn = (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true);
        $logOn   = (new SettingDefinition())->setKey('notifications.email_log_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setCentreScope(true);
        $this->persist($this->centre, $this->previous, $this->year, $this->manager, $this->teacher, $emailOn, $logOn);
    }

    private function service(): IndicatorService
    {
        /** @var IndicatorService $service */
        $service = self::getContainer()->get(IndicatorService::class);

        return $service;
    }

    /** "Alumnado que promociona", more is better, target 85 and alert from 80, recorded by Ana. */
    private function indicator(AcademicYear $year, MeasurementCalendar $calendar): Indicator
    {
        return $this->service()->saveIndicator($this->centre, null, $year, [
            'name' => 'Alumnado que promociona', 'description' => null, 'section' => null, 'unit' => '%', 'higherIsBetter' => true,
            'teacher' => $this->teacher, 'profile' => null, 'active' => true, 'calendar' => $calendar, 'target' => 85.0, 'alertThreshold' => 80.0,
        ]);
    }

    /** @return list<string> "type:label · period (urgency)" */
    private function tasks(Teacher $teacher): array
    {
        /** @var QualityTaskFinder $finder */
        $finder = self::getContainer()->get(QualityTaskFinder::class);

        return array_map(static fn (QualityTask $t): string => $t->type . ':' . $t->label() . ' · ' . $t->code() . ' (' . $t->urgency . ')', $finder->forTeacher($teacher, $this->centre));
    }

    public function testAPeriodAsksForItsValueOnceOverAndAValueOffTargetForADecision(): void
    {
        $calendar  = $this->service()->createCalendar($this->centre, $this->year, 'Evaluaciones', 'evaluations');
        $indicator = $this->indicator($this->year, $calendar);

        // The 1st evaluation ended on Dec 22: Ana has until Jan 6 (15 days by default), 9 days away.
        self::assertSame(['measure:Alumnado que promociona · 1.ª evaluación (open)'], $this->tasks($this->teacher));
        self::assertSame([], $this->tasks($this->manager));

        $first       = $calendar->getPeriods()->first() ?: throw new \LogicException();
        $measurement = $this->service()->record($indicator, $first, 78.0, 'Datos de Séneca', $this->teacher);
        self::assertSame(IndicatorStatus::OffTarget, $measurement->status());
        self::assertSame([], $this->tasks($this->teacher));
        self::assertSame(['review:Alumnado que promociona · 1.ª evaluación (open)'], $this->tasks($this->manager));

        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);
        self::assertSame(['quality_measurement_off_target'], array_map(static fn ($l): string => $l->getEventKey(), $logs->findAll()));

        // Corrected to a value in alert, it no longer needs a decision.
        $this->service()->record($indicator, $first, 82.0, null, $this->teacher);
        self::assertSame(IndicatorStatus::Alert, $measurement->status());
        self::assertSame([], $this->tasks($this->manager));
    }

    public function testAValueOffTargetBecomesAFindingOrNothing(): void
    {
        $calendar  = $this->service()->createCalendar($this->centre, $this->year, 'Evaluaciones', 'evaluations');
        $indicator = $this->indicator($this->year, $calendar);
        $periods   = $calendar->getPeriods()->toArray();

        $first   = $this->service()->record($indicator, $periods[0], 70.0, null, $this->teacher);
        $finding = $this->service()->openFinding($first, $this->manager);
        self::assertSame(FindingStatus::Reported, $finding->getStatus());
        self::assertSame(FindingOrigin::Indicator, $finding->getOrigin());
        self::assertSame($first, $finding->getMeasurement());
        self::assertStringContainsString('«Alumnado que promociona» ha quedado fuera de meta en 1.ª evaluación', $finding->getTitle());
        self::assertStringContainsString('Valor: 70 % (meta: 85 %)', $finding->getDescription());
        self::assertTrue($first->isReviewed());

        self::mockTime('2027-04-01 10:00:00');
        $second = $this->service()->record($indicator, $periods[1], 71.0, null, $this->teacher);
        $this->service()->dismiss($second, $this->manager, 'Un grupo excepcional');
        self::assertSame('Un grupo excepcional', $second->getReviewNote());
        self::assertNotContains('review', array_map(static fn (string $t): string => explode(':', $t)[0], $this->tasks($this->manager)));
    }

    public function testEditingACalendarKeepsTheValuesOfThePeriodsItKeeps(): void
    {
        $calendar  = $this->service()->createCalendar($this->centre, $this->year, 'Trimestral', 'terms');
        $indicator = $this->indicator($this->year, $calendar);
        [$first, $second] = $calendar->getPeriods()->toArray();
        $value = $this->service()->record($indicator, $first, 90.0, null, $this->teacher);

        $this->service()->saveCalendar($calendar, 'Trimestres', [
            ['id' => $second->getId()->toRfc4122(), 'name' => '2.º trimestre', 'start' => $second->getStartDate(), 'end' => $second->getEndDate()],
            ['id' => $first->getId()->toRfc4122(), 'name' => 'Primer trimestre', 'start' => $first->getStartDate(), 'end' => $first->getEndDate()],
            ['id' => null, 'name' => 'Evaluación inicial', 'start' => new \DateTimeImmutable('2026-09-15'), 'end' => new \DateTimeImmutable('2026-10-10')],
        ]);
        $this->em->clear();

        $calendar = $this->em->find(MeasurementCalendar::class, $calendar->getId());
        self::assertNotNull($calendar);
        self::assertSame('Trimestres', $calendar->getName());
        // In order of dates; the 3rd term, left out, is gone.
        self::assertSame(['Evaluación inicial', 'Primer trimestre', '2.º trimestre'], array_map(static fn ($p): string => $p->getName(), $calendar->getPeriods()->toArray()));
        self::assertNotNull($this->em->find(\App\Entity\Measurement::class, $value->getId()));
    }

    public function testANewYearCopiesTheCalendarsAYearLaterAndTheTargets(): void
    {
        $calendar  = $this->service()->createCalendar($this->centre, $this->previous, 'Evaluaciones', 'evaluations');
        $indicator = $this->indicator($this->previous, $calendar);
        $this->service()->record($indicator, $calendar->getPeriods()->last() ?: throw new \LogicException(), 88.0, null, $this->teacher);

        self::assertSame(['calendars' => 1, 'targets' => 1], $this->service()->copyYear($this->centre, $this->previous, $this->year));
        // Twice changes nothing.
        self::assertSame(['calendars' => 0, 'targets' => 0], $this->service()->copyYear($this->centre, $this->previous, $this->year));

        $target = $indicator->targetFor($this->year);
        self::assertNotNull($target);
        self::assertSame([85.0, 80.0], [$target->getTarget(), $target->getAlertThreshold()]);
        $copy = $target->getCalendar();
        self::assertNotNull($copy);
        self::assertNotSame($calendar, $copy);
        self::assertSame('2026-09-15', ($copy->getPeriods()->first() ?: throw new \LogicException())->getStartDate()->format('Y-m-d'));

        // The board compares with last year's same period (or its last one).
        /** @var IndicatorBoardBuilder $board */
        $board = self::getContainer()->get(IndicatorBoardBuilder::class);
        $row   = $board->row($indicator, $this->year);
        self::assertSame(['value' => 88.0, 'label' => '2025-2026 · Final 2'], $row->previous);
        self::assertNull($row->latest);
        // The 1st evaluation isn't late yet (until Jan 6): no status.
        self::assertNull($row->status);
    }
}
