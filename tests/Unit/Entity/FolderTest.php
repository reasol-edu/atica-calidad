<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AllowedFileFormat;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use PHPUnit\Framework\TestCase;

final class FolderTest extends TestCase
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

    private function profile(EducationalCentre $centre, string $name = 'Perfil'): SpecificProfile
    {
        return (new SpecificProfile())->setEducationalCentre($centre)->setName($name);
    }

    private function listItem(EducationalCentre $centre, string $name = 'Item'): ListItem
    {
        return (new ListItem())->setEducationalCentre($centre)->setName($name);
    }

    public function testRequiresReviewFalseWithoutReviewProfiles(): void
    {
        $folder = $this->folder($this->centre());

        self::assertFalse($folder->requiresReview());
    }

    public function testRequiresReviewTrueWithAtLeastOneReviewProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = $this->profile($centre);

        $folder->addReviewProfile($profile);

        self::assertTrue($folder->requiresReview());
    }

    public function testAddResponsibleProfileDoesNotDuplicateSamePair(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $profile  = $this->profile($centre);
        $listItem = $this->listItem($centre);

        $folder->addResponsibleProfile($profile, $listItem);
        $folder->addResponsibleProfile($profile, $listItem);

        self::assertCount(1, $folder->getResponsibleProfiles());
    }

    public function testAddResponsibleProfileTreatsDifferentListItemAsDistinct(): void
    {
        $centre    = $this->centre();
        $folder    = $this->folder($centre);
        $profile   = $this->profile($centre);
        $listItemA = $this->listItem($centre, 'A');
        $listItemB = $this->listItem($centre, 'B');

        $folder->addResponsibleProfile($profile, $listItemA);
        $folder->addResponsibleProfile($profile, $listItemB);
        $folder->addResponsibleProfile($profile, null);

        self::assertCount(3, $folder->getResponsibleProfiles());
        self::assertTrue($folder->hasResponsibleProfile($profile, $listItemA));
        self::assertTrue($folder->hasResponsibleProfile($profile, $listItemB));
        self::assertTrue($folder->hasResponsibleProfile($profile, null));
    }

    public function testAddUploadProfileDoesNotDuplicateSamePair(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = $this->profile($centre);

        $folder->addUploadProfile($profile);
        $folder->addUploadProfile($profile);

        self::assertCount(1, $folder->getUploadProfiles());
    }

    public function testAddVisibilityProfileDoesNotDuplicateAndTogglesRestriction(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = $this->profile($centre);

        self::assertFalse($folder->isVisibilityRestricted());

        $folder->addVisibilityProfile($profile);
        $folder->addVisibilityProfile($profile);

        self::assertCount(1, $folder->getVisibilityProfiles());
        self::assertTrue($folder->isVisibilityRestricted());
    }

    public function testAddReviewProfileDoesNotDuplicateSamePair(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = $this->profile($centre);

        $folder->addReviewProfile($profile);
        $folder->addReviewProfile($profile);

        self::assertCount(1, $folder->getReviewProfiles());
    }

    public function testIsLeafReflectsDocuments(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);

        self::assertTrue($folder->isLeaf());

        $folder->getDocuments()->add(new \App\Entity\Document($folder, 'Doc'));

        self::assertFalse($folder->isLeaf());
    }

    public function testGetEducationalCentreDelegatesToSection(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);

        self::assertSame($centre, $folder->getEducationalCentre());
    }

    public function testActivityIsNullByDefault(): void
    {
        $folder = $this->folder($this->centre());

        self::assertNull($folder->getActivity());
    }

    // ── Allowed formats ──────────────────────────────────────────────────────

    public function testUnrestrictedFolderAcceptsAnyFile(): void
    {
        $folder = $this->folder($this->centre());

        self::assertFalse($folder->isFormatRestricted());
        self::assertSame([], $folder->getAllowedFormats());
        self::assertTrue($folder->acceptsFile('cualquiera.exe', 'application/octet-stream'));
    }

    public function testSetAllowedFormatsRestrictsToTheChosenFormats(): void
    {
        $folder = $this->folder($this->centre());

        $folder->setAllowedFormats([AllowedFileFormat::Image, AllowedFileFormat::NonEditableDocument]);

        self::assertTrue($folder->isFormatRestricted());
        self::assertSame([AllowedFileFormat::Image, AllowedFileFormat::NonEditableDocument], $folder->getAllowedFormats());
        self::assertTrue($folder->acceptsFile('foto.jpg', 'image/jpeg'));
        self::assertTrue($folder->acceptsFile('memoria.pdf', 'application/pdf'));
        self::assertFalse($folder->acceptsFile('datos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    }

    public function testSetAllowedFormatsWithAnEmptyArrayClearsTheRestriction(): void
    {
        $folder = $this->folder($this->centre());
        $folder->setAllowedFormats([AllowedFileFormat::Image]);

        $folder->setAllowedFormats([]);

        self::assertFalse($folder->isFormatRestricted());
        self::assertTrue($folder->acceptsFile('script.exe', 'application/octet-stream'));
    }

    public function testSetAllowedFormatsDeduplicatesRepeatedFormats(): void
    {
        $folder = $this->folder($this->centre());

        $folder->setAllowedFormats([AllowedFileFormat::Image, AllowedFileFormat::Image]);

        self::assertSame([AllowedFileFormat::Image], $folder->getAllowedFormats());
    }

    public function testAcceptsFileMatchesByExtensionEvenWithAGenericMimeType(): void
    {
        $folder = $this->folder($this->centre());
        $folder->setAllowedFormats([AllowedFileFormat::Text]);

        self::assertTrue($folder->acceptsFile('notas.TXT', 'application/octet-stream'), 'extension match is case-insensitive');
    }

    public function testAcceptsFileMatchesByMimeTypeEvenWithAnUnexpectedExtension(): void
    {
        $folder = $this->folder($this->centre());
        $folder->setAllowedFormats([AllowedFileFormat::Image]);

        self::assertTrue($folder->acceptsFile('foto.jpeg_renamed', 'image/jpeg'));
    }

    public function testAcceptsFileRejectsWhatNoAllowedFormatCovers(): void
    {
        $folder = $this->folder($this->centre());
        $folder->setAllowedFormats([AllowedFileFormat::Spreadsheet]);

        self::assertFalse($folder->acceptsFile('instalador.exe', 'application/x-msdownload'));
    }
}
