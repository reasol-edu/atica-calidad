<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Service\ActivityDeadlineChecker;
use App\Tests\Integration\RepositoryTestCase;
use App\Twig\ActivityCycleExtension;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityCycleExtensionTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private function extension(): ActivityCycleExtension
    {
        /** @var ActivityDeadlineChecker $deadline */
        $deadline = self::getContainer()->get(ActivityDeadlineChecker::class);

        return new ActivityCycleExtension($deadline);
    }

    /** @return array{Folder, EducationalCentre, DocumentSection} */
    private function folder(): array
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return [(new Folder())->setDocumentSection($section)->setName('Carpeta'), $centre, $section];
    }

    public function testLabelsOnlySubmissionsFromAnEarlierAcademicYear(): void
    {
        self::mockTime('2026-10-10 10:00:00');
        [$folder, $centre, $section] = $this->folder();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 10)->setEnd(31, 10)->setFolder($folder);
        $old      = (new Document($folder, 'Tutor/a'))->setActivityCycleYear(2025);
        $current  = new Document($folder, 'Tutor/a');
        $this->persist($centre, $section, $folder, $category, $activity, $old, $current);

        self::assertSame('2025-2026', $this->extension()->pastSubmissionAcademicYear($old));
        self::assertNull($this->extension()->pastSubmissionAcademicYear($current), 'this year\'s submission needs no label');
    }

    public function testNeverLabelsADocumentOutsideAnActivityFolder(): void
    {
        [$folder, $centre, $section] = $this->folder();
        $document = new Document($folder, 'Acta');
        $this->persist($centre, $section, $folder, $document);

        self::assertNull($this->extension()->pastSubmissionAcademicYear($document));
    }
}
