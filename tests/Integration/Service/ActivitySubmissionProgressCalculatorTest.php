<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
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
use App\Service\ActivitySubmissionProgressCalculator;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivitySubmissionProgressCalculatorTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private ActivitySubmissionProgressCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2025-10-10 10:00:00');

        /** @var ActivitySubmissionProgressCalculator $calculator */
        $calculator       = self::getContainer()->get(ActivitySubmissionProgressCalculator::class);
        $this->calculator = $calculator;
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName($username, 'Apellido')))->setUsername($username);
    }

    /** "pending", "rejected" or "approved"; $cycleYear null = stamped automatically (this year's). */
    private function submission(Folder $folder, SpecificProfile $profile, string $name, Teacher $uploader, string $state, ?int $cycleYear = null): Document
    {
        $document = (new Document($folder, $name))->setUploadProfile($profile)->setActivityCycleYear($cycleYear);
        $file     = new DocumentFile(hash('sha256', $name . $state . $uploader->getUsername() . $cycleYear), 'x', 'application/pdf', 'x.pdf', 1);
        $revision = new DocumentRevision($document, 1, $file, $state !== 'approved', $uploader);
        $document->getRevisions()->add($revision);
        if ($state === 'approved') {
            $document->setActiveRevision($revision);
        } elseif ($state === 'rejected') {
            $revision->reject($uploader, null);
        }
        $this->persist($file, $document, $revision);

        return $document;
    }

    /** @return array{EducationalCentre, Folder, Activity, list<SpecificProfile>} a by-profile Oct 1–31 activity with one slot per profile */
    private function byProfileActivity(int $profiles): array
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $list     = [];
        for ($i = 1; $i <= $profiles; ++$i) {
            $list[] = $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil ' . $i);
            $folder->addUploadProfile($profile);
        }
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 10)->setEnd(31, 10)
            ->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $this->persist($centre, $category, $section, $folder, $activity, ...$list);

        return [$centre, $folder, $activity, $list];
    }

    public function testCountsEachSlotByTheStateOfItsSubmission(): void
    {
        [, $folder, $activity, $profiles] = $this->byProfileActivity(5);
        $teacher = $this->teacher('docente');
        $this->persist($teacher);
        $this->submission($folder, $profiles[0], 'Perfil 1', $teacher, 'approved');
        $this->submission($folder, $profiles[1], 'Perfil 2', $teacher, 'pending');
        $this->submission($folder, $profiles[2], 'Perfil 3', $teacher, 'rejected');
        $this->submission($folder, $profiles[3], 'Perfil 4', $teacher, 'approved', cycleYear: 2024); // last year's: doesn't count
        // Perfil 5: nothing submitted.

        $progress = $this->calculator->forActivity($activity);

        self::assertNotNull($progress);
        self::assertSame(5, $progress->total);
        self::assertSame(3, $progress->delivered);
        self::assertSame(1, $progress->accepted);
        self::assertSame(1, $progress->inReview);
        self::assertSame(1, $progress->rejected);
        self::assertSame(60, $progress->deliveredPercentage());
    }

    /** Individual scope: several teachers submit under the same name — each slot pairs with its own teacher's document. */
    public function testAnIndividualSlotOnlyMatchesItsOwnTeachersSubmission(): void
    {
        [$centre, $folder, $activity, $profiles] = $this->byProfileActivity(1);
        $activity->setSubmissionScope(ActivitySubmissionScope::Individual);
        $ana  = $this->teacher('ana');
        $luis = $this->teacher('luis');
        $this->persist($ana, $luis, new SpecificProfileAssignment($profiles[0], null, $ana), new SpecificProfileAssignment($profiles[0], null, $luis));
        $this->submission($folder, $profiles[0], 'Perfil 1', $ana, 'approved');

        $progress = $this->calculator->forActivity($activity);

        self::assertNotNull($progress);
        self::assertSame(2, $progress->total);
        self::assertSame(1, $progress->delivered, 'Luis has not submitted, even though Ana\'s document carries the same name');
    }

    public function testAnActivityWithoutAFolderHasNoProgress(): void
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Sin carpeta')->setStart(1, 10)->setEnd(31, 10);
        $this->persist($centre, $category, $activity);

        self::assertNull($this->calculator->forActivity($activity));
    }
}
