<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Service\ActivityStatusReportBuilder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityStatusReportBuilderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    public function testMeasuresSubmissionsForAFolderActivityAndCompletionsForAManualOne(): void
    {
        self::mockTime('2025-10-10 10:00:00');
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Evaluación');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Memorias');
        $a        = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil A');
        $b        = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil B');
        $folder->addUploadProfile($a);
        $folder->addUploadProfile($b);
        $withFolder = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 10)->setEnd(31, 10)
            ->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $manual = (new Activity())->setCategory($category)->setTitle('Lectura del plan')->setStart(1, 10)->setEnd(31, 10);
        $teachers = [];
        foreach (['uno', 'dos', 'tres'] as $name) {
            $teachers[] = $teacher = (new Teacher(new PersonName('Nombre', $name)))->setUsername($name);
            $year->addTeacher($teacher);
        }
        $this->persist($centre, $year, $category, $section, $folder, $a, $b, $withFolder, $manual, ...$teachers);

        $document = (new Document($folder, 'Perfil A'))->setUploadProfile($a);
        $file     = new DocumentFile(hash('sha256', 'memoria'), 'x', 'application/pdf', 'x.pdf', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teachers[0]);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($file, $document, $revision, new ActivityCompletion($manual, $teachers[1], null, null, $teachers[1], 2025));

        /** @var ActivityStatusReportBuilder $builder */
        $builder = self::getContainer()->get(ActivityStatusReportBuilder::class);
        $rows    = [];
        foreach ($builder->build($centre) as $row) {
            $rows[$row->title] = $row;
        }

        self::assertTrue($rows['Memoria']->withSubmissions);
        self::assertSame(2, $rows['Memoria']->expected);
        self::assertSame(1, $rows['Memoria']->done);
        self::assertSame(50, $rows['Memoria']->donePercentage());

        self::assertFalse($rows['Lectura del plan']->withSubmissions);
        self::assertSame(3, $rows['Lectura del plan']->expected, 'every teacher of the active academic year');
        self::assertSame(1, $rows['Lectura del plan']->done);
        self::assertSame('2025-10-31', $rows['Lectura del plan']->deadline->format('Y-m-d'));
    }
}
