<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Service\ActivityComplianceReportBuilder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityComplianceReportBuilderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    /** A completion stored against $cycle and made on $when (the entity stamps "now", so it's set through reflection). */
    private function completion(Activity $activity, Teacher $teacher, int $cycle, string $when): ActivityCompletion
    {
        $completion = new ActivityCompletion($activity, $teacher, null, null, $teacher, $cycle);
        $property   = new \ReflectionProperty(ActivityCompletion::class, 'completedAt');
        $property->setValue($completion, new \DateTimeImmutable($when));

        return $completion;
    }

    public function testComparesAManualActivityAndASubmissionOneAcrossTwoYears(): void
    {
        self::mockTime('2026-10-05 10:00:00');
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $y2025    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $y2026    = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($y2026);
        $one      = (new Teacher(new PersonName('Uno', 'uno')))->setUsername('uno');
        $two      = (new Teacher(new PersonName('Dos', 'dos')))->setUsername('dos');
        $y2025->addTeacher($one);
        $y2025->addTeacher($two);
        $y2026->addTeacher($one);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Seguimiento');
        // Sep 1 – Oct 31: cycle 2025's deadline is 2025-10-31, 2026's 2026-10-31.
        $manual = (new Activity())->setCategory($category)->setTitle('Lectura')->setStart(1, 9)->setEnd(31, 10);
        $this->persist($centre, $y2025, $y2026, $one, $two, $category, $manual);
        // 2025: two teachers, one in time, one late. 2026: one teacher, in time.
        $this->persist(
            $this->completion($manual, $one, 2025, '2025-10-20'),
            $this->completion($manual, $two, 2025, '2025-11-15'),
            $this->completion($manual, $one, 2026, '2026-10-02'),
        );

        /** @var ActivityComplianceReportBuilder $builder */
        $builder = self::getContainer()->get(ActivityComplianceReportBuilder::class);

        self::assertSame([2026, 2025], $builder->availableCycles($centre));
        self::assertSame(2026, $builder->currentCycle($centre));

        $rows = $builder->build($centre, 2026, 2025);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('Lectura', $row->title);
        self::assertFalse($row->withSubmissions);

        // 2026-2027 has one teacher: 1 of 1, in time.
        self::assertSame([1, 1, 1], [$row->current->expected, $row->current->done, $row->current->onTime]);
        self::assertSame(100, $row->current->percentage());
        // 2025-2026 had two: 2 of 2 done, only one in time.
        self::assertNotNull($row->previous);
        self::assertSame([2, 2, 1], [$row->previous->expected, $row->previous->done, $row->previous->onTime]);
        self::assertSame(50, $row->previous->onTimePercentage());
        self::assertSame(0, $row->delta(), '100 % both years');
    }

    public function testCountsAcceptedAndLateDocumentsOfAnActivityWithAFolder(): void
    {
        self::mockTime('2026-10-05 10:00:00');
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year     = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher  = (new Teacher(new PersonName('Uno', 'uno')))->setUsername('uno');
        $year->addTeacher($teacher);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Seguimiento');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Memorias');
        $a        = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil A');
        $b        = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil B');
        $folder->addUploadProfile($a)->addUploadProfile($b);
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
        $this->persist($centre, $year, $teacher, $category, $section, $folder, $a, $b, $activity, new SpecificProfileAssignment($a, null, $teacher));

        $file = new DocumentFile(hash('sha256', 'x'), 'x', 'application/pdf', 'x.pdf', 1);
        $this->persist($file);
        // Profile A's document: accepted, uploaded within the deadline. Profile B's: sent, never approved.
        $accepted = (new Document($folder, 'Perfil A'))->setUploadProfile($a)->setActivityCycleYear(2026);
        $revision = new DocumentRevision($accepted, 1, $file, false, $teacher);
        $accepted->getRevisions()->add($revision);
        $accepted->setActiveRevision($revision);
        $waiting  = (new Document($folder, 'Perfil B'))->setUploadProfile($b)->setActivityCycleYear(2026);
        $pending  = new DocumentRevision($waiting, 1, $file, true, $teacher);
        $waiting->getRevisions()->add($pending);
        $this->persist($accepted, $revision, $waiting, $pending);

        /** @var ActivityComplianceReportBuilder $builder */
        $builder = self::getContainer()->get(ActivityComplianceReportBuilder::class);
        $row     = $builder->build($centre, 2026)[0];

        self::assertTrue($row->withSubmissions);
        self::assertNull($row->previous);
        self::assertSame(2, $row->current->expected);
        self::assertSame(2, $row->current->delivered);
        self::assertSame(1, $row->current->done);
        self::assertSame(50, $row->current->percentage());
    }
}
