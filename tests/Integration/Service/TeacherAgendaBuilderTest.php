<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\ImprovementActionType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Model\AgendaEntry;
use App\Service\FindingService;
use App\Service\TeacherAgendaBuilder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TeacherAgendaBuilderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    public function testActivitiesAndQualityTasksShareOneListByUrgency(): void
    {
        self::mockTime('2026-10-05 10:00:00');
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year    = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $manager = (new Teacher(new PersonName('Laura', 'Calidad')))->setUsername('calidad');
        $teacher = (new Teacher(new PersonName('Luis', 'Docente')))->setUsername('docente');
        $year->addTeacher($teacher);
        $centre->addQualityManager($manager);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Seguimiento');
        $later    = (new Activity())->setCategory($category)->setTitle('Lejana')->setStart(1, 9)->setEnd(20, 10);
        $thisWeek = (new Activity())->setCategory($category)->setTitle('De esta semana')->setStart(1, 9)->setEnd(8, 10);
        $this->persist($centre, $year, $manager, $teacher, $category, $later, $thisWeek);

        /** @var FindingService $findings */
        $findings = self::getContainer()->get(FindingService::class);
        $plan     = static fn (string $name, string $due) => $findings->createPlanAction($centre, $year, $manager, ImprovementActionType::Improvement, $name, null, null, $teacher, null, new \DateTimeImmutable($due));
        $plan('Tarea vencida', '2026-09-20');
        $plan('Tarea de esta semana', '2026-10-06');

        /** @var TeacherAgendaBuilder $builder */
        $builder = self::getContainer()->get(TeacherAgendaBuilder::class);
        $labels  = array_map(
            static fn (AgendaEntry $e): string => $e->bucket . ':' . ($e->activity?->activity->getTitle() ?? $e->task?->label()),
            $builder->build($teacher, $centre),
        );

        self::assertSame([
            'overdue:Tarea vencida',
            'week:Tarea de esta semana',
            'week:De esta semana',
            'later:Lejana',
        ], $labels);
    }
}
