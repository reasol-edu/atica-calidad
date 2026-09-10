<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ActivityWindowBlock;
use App\Service\ActivityWindowChecker;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityWindowCheckerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', ucfirst($username))))->setUsername($username);
    }

    /** A folder-backed activity from 1/3 to 30/6 (non-wrapping), with $enforce flags applied. */
    private function activity(EducationalCentre $centre, bool $start, bool $end, int $grace = 0): Activity
    {
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Cat');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sec');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 3)->setEnd(30, 6);
        $activity->setFolder($folder);
        $activity->setStartDateEnforced($start);
        $activity->setEndDateEnforced($end);
        $activity->setEndDateGraceDays($grace);

        $this->persist($centre, $category, $section, $folder, $activity);

        return $activity;
    }

    private function checker(): ActivityWindowChecker
    {
        /** @var ActivityWindowChecker $checker */
        $checker = self::getContainer()->get(ActivityWindowChecker::class);

        return $checker;
    }

    public function testOpenWhenInsideThePeriod(): void
    {
        self::mockTime('2026-05-01 12:00:00');
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $activity = $this->activity($centre, start: true, end: true);

        $window = $this->checker()->for($activity, $teacher);

        self::assertFalse($window->blocked);
        self::assertFalse($window->late);
        self::assertNull($window->reason);
    }

    public function testBlockedBeforeTheStartDate(): void
    {
        self::mockTime('2026-02-01 12:00:00');
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $activity = $this->activity($centre, start: true, end: true);

        $window = $this->checker()->for($activity, $teacher);

        self::assertTrue($window->blocked);
        self::assertSame(ActivityWindowBlock::BeforeStart, $window->reason);
    }

    public function testNotBlockedBeforeStartWhenTheStartFlagIsOff(): void
    {
        self::mockTime('2026-02-01 12:00:00');
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $activity = $this->activity($centre, start: false, end: true);

        self::assertFalse($this->checker()->for($activity, $teacher)->blocked);
    }

    public function testLateButAllowedInsideTheGracePeriod(): void
    {
        self::mockTime('2026-07-03 12:00:00'); // deadline 30/6, grace 7 days -> until 7/7
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $activity = $this->activity($centre, start: true, end: true, grace: 7);

        $window = $this->checker()->for($activity, $teacher);

        self::assertFalse($window->blocked);
        self::assertTrue($window->late);
        self::assertSame('2026-07-07', $window->graceUntil->format('Y-m-d'));
    }

    public function testBlockedOnceTheGracePeriodExpires(): void
    {
        self::mockTime('2026-07-10 12:00:00');
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $activity = $this->activity($centre, start: true, end: true, grace: 7);

        $window = $this->checker()->for($activity, $teacher);

        self::assertTrue($window->blocked);
        self::assertSame(ActivityWindowBlock::AfterEnd, $window->reason);
    }

    public function testFolderResponsibleBypassesAndIsFlaggedLate(): void
    {
        self::mockTime('2026-08-01 12:00:00'); // well past deadline + grace
        $centre  = $this->centre();
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $this->persist($centre, $manager, $profile, new SpecificProfileAssignment($profile, null, $manager));

        $activity = $this->activity($centre, start: true, end: true, grace: 7);
        $activity->getFolder()?->addResponsibleProfile($profile);
        $this->flush();

        $window = $this->checker()->for($activity, $manager);

        self::assertFalse($window->blocked);
        self::assertTrue($window->late);
        self::assertTrue($window->bypassing);
    }

    public function testQualityManagerAndAdminBypass(): void
    {
        self::mockTime('2026-08-01 12:00:00');
        $centre = $this->centre();
        $qm     = $this->teacher('calidad');
        $admin  = $this->teacher('root')->setAdmin(true);
        $centre->addQualityManager($qm);
        $this->persist($centre, $qm, $admin);
        $activity = $this->activity($centre, start: true, end: true);

        self::assertFalse($this->checker()->for($activity, $qm)->blocked);
        self::assertFalse($this->checker()->for($activity, $admin)->blocked);
    }

    public function testFolderlessActivityBypassIsAdminOrQualityManagerOnly(): void
    {
        self::mockTime('2026-02-01 12:00:00');
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Cat');
        $docente  = $this->teacher('docente');
        $qm       = $this->teacher('calidad');
        $centre->addQualityManager($qm);
        $activity = (new Activity())->setCategory($category)->setTitle('Recordatorio')->setStart(1, 3)->setEnd(30, 6);
        $activity->setStartDateEnforced(true);
        $this->persist($centre, $category, $docente, $qm, $activity);

        self::assertTrue($this->checker()->for($activity, $docente)->blocked);
        self::assertFalse($this->checker()->for($activity, $qm)->blocked);
    }
}
