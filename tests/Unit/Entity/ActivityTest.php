<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use PHPUnit\Framework\TestCase;

final class ActivityTest extends TestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function activity(ActivityCategory $category): Activity
    {
        return (new Activity())->setCategory($category)->setTitle('Actividad');
    }

    public function testSetFolderRejectsFolderFromAnotherCentre(): void
    {
        $centre   = $this->centre();
        $activity = $this->activity($this->category($centre));
        $folder   = $this->folder($this->centre('87654321'));

        $this->expectException(\LogicException::class);
        $activity->setFolder($folder);
    }

    public function testSetFolderAcceptsSameCentreFolder(): void
    {
        $centre   = $this->centre();
        $activity = $this->activity($this->category($centre));
        $folder   = $this->folder($centre);

        $activity->setFolder($folder);

        self::assertSame($folder, $activity->getFolder());
        self::assertTrue($activity->requiresSubmissions());
    }

    public function testClearingFolderTurnsOffAutoComplete(): void
    {
        $centre   = $this->centre();
        $activity = $this->activity($this->category($centre));
        $activity->setFolder($this->folder($centre));
        $activity->setAutoComplete(true);
        self::assertTrue($activity->isAutoComplete());

        $activity->setFolder(null);

        self::assertFalse($activity->isAutoComplete());
        self::assertFalse($activity->requiresSubmissions());
    }

    public function testSetAutoCompleteRejectedWithoutFolder(): void
    {
        $activity = $this->activity($this->category($this->centre()));

        $this->expectException(\LogicException::class);
        $activity->setAutoComplete(true);
    }

    public function testSetAutoCompleteAllowedWithFolder(): void
    {
        $centre   = $this->centre();
        $activity = $this->activity($this->category($centre));
        $activity->setFolder($this->folder($centre));

        $activity->setAutoComplete(true);

        self::assertTrue($activity->isAutoComplete());
    }

    public function testRequiresSubmissionsFalseWithoutFolder(): void
    {
        $activity = $this->activity($this->category($this->centre()));

        self::assertFalse($activity->requiresSubmissions());
    }

    public function testDefaultSubmissionScopeIsByProfile(): void
    {
        $activity = $this->activity($this->category($this->centre()));

        self::assertSame(\App\Entity\ActivitySubmissionScope::ByProfile, $activity->getSubmissionScope());
    }
}
