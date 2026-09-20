<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

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
use App\Entity\Teacher;
use App\Service\ActivitySubmissionFilenameBuilder;
use PHPUnit\Framework\TestCase;

final class ActivitySubmissionFilenameBuilderTest extends TestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    /**
     * Activity::setFolder() only sets its own side — Folder::$activity is the inverse side of a
     * bidirectional one-to-one, normally populated by Doctrine's own hydration when loading from
     * the database, which this pure in-memory test never does. Mirroring that one write here via
     * reflection makes $folder->getActivity() resolve the same way it would after a real fetch.
     */
    private function activity(EducationalCentre $centre, Folder $folder, string $title = 'Programación didáctica'): Activity
    {
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle($title)->setFolder($folder);

        $property = new \ReflectionProperty(Folder::class, 'activity');
        $property->setAccessible(true);
        $property->setValue($folder, $activity);

        return $activity;
    }

    private function teacher(string $firstName, string $lastName): Teacher
    {
        return (new Teacher(new PersonName($firstName, $lastName)))->setUsername(strtolower($lastName));
    }

    private function document(Folder $folder, string $name, Teacher $uploader): Document
    {
        $document = new Document($folder, $name);
        $file     = new DocumentFile(hash('sha256', $name), $name, 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        return $document;
    }

    private function builder(): ActivitySubmissionFilenameBuilder
    {
        return new ActivitySubmissionFilenameBuilder();
    }

    public function testAPlainDocumentTreeFileIsJustItsOwnName(): void
    {
        $centre   = $this->centre();
        $document = $this->document($this->folder($centre), 'Manual de calidad', $this->teacher('Ana', 'García'));

        self::assertSame(['Manual de calidad'], $this->builder()->nameParts($document));
    }

    public function testAnActivitySubmissionIsLedByTheActivityTitleByDefault(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $activity = $this->activity($centre, $folder);
        $document = $this->document($folder, 'Tutor/a', $this->teacher('Ana', 'García'));

        self::assertSame(['Programación didáctica', 'Tutor/a'], $this->builder()->nameParts($document));
    }

    public function testTheActivitysOwnSubmissionPrefixReplacesTheTitleWhenSet(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $activity = $this->activity($centre, $folder)->setSubmissionPrefix('PD');
        $document = $this->document($folder, 'Tutor/a', $this->teacher('Ana', 'García'));

        self::assertSame(['PD', 'Tutor/a'], $this->builder()->nameParts($document));
    }

    public function testASingleHyphenPrefixOmitsTheLeadingPartEntirely(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $activity = $this->activity($centre, $folder)->setSubmissionPrefix('-');
        $document = $this->document($folder, 'Tutor/a', $this->teacher('Ana', 'García'));

        self::assertSame(['Tutor/a'], $this->builder()->nameParts($document));
    }

    public function testAnIndividualScopeSubmissionIsTrailedByTheUploadersName(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('Ana', 'García');
        $activity = $this->activity($centre, $folder)->setSubmissionScope(ActivitySubmissionScope::Individual);
        $document = $this->document($folder, 'Tutor/a', $uploader);

        self::assertSame(['Programación didáctica', 'Tutor/a', 'García, Ana'], $this->builder()->nameParts($document));
    }

    public function testAByProfileScopeSubmissionIsNeverTrailedByAnyonesName(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $activity = $this->activity($centre, $folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $document = $this->document($folder, 'Tutor/a', $this->teacher('Ana', 'García'));

        self::assertSame(['Programación didáctica', 'Tutor/a'], $this->builder()->nameParts($document));
    }

    /** The uploader can only be told apart from version 1's own uploader (see Document::getFirstRevision()) — deleted outright, there is no one left to name. */
    public function testAnIndividualScopeSubmissionWithoutAFirstRevisionIsNotTrailedByAnyone(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $activity = $this->activity($centre, $folder)->setSubmissionScope(ActivitySubmissionScope::Individual);
        $document = new Document($folder, 'Tutor/a');
        $document->getRevisions()->add(new DocumentRevision($document, 2, new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1), false, $this->teacher('Ana', 'García')));

        self::assertSame(['Programación didáctica', 'Tutor/a'], $this->builder()->nameParts($document));
    }
}
