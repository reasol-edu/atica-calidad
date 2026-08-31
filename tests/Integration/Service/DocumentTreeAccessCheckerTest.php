<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Service\DocumentTreeAccessChecker;
use App\Tests\Integration\RepositoryTestCase;

final class DocumentTreeAccessCheckerTest extends RepositoryTestCase
{
    private DocumentTreeAccessChecker $access;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DocumentTreeAccessChecker $access */
        $access       = self::getContainer()->get(DocumentTreeAccessChecker::class);
        $this->access = $access;
    }

    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    private function section(EducationalCentre $centre, string $name = 'Sección'): DocumentSection
    {
        return (new DocumentSection())->setEducationalCentre($centre)->setName($name);
    }

    private function folder(DocumentSection $section): Folder
    {
        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    // ── canViewSection ────────────────────────────────────────────────────────

    public function testCanViewSectionOpenByDefault(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $section, $teacher);

        self::assertTrue($this->access->canViewSection($teacher, $section));
    }

    public function testCanViewSectionDeniedWhenRestrictedAndTeacherLacksProfile(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $section->addProfileRestriction($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $section, $profile, $teacher);

        self::assertFalse($this->access->canViewSection($teacher, $section));
    }

    public function testCanViewSectionGrantedWhenTeacherHoldsRestrictingProfile(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $section->addProfileRestriction($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $section, $profile, $teacher, $assignment);

        self::assertTrue($this->access->canViewSection($teacher, $section));
    }

    public function testCanViewSectionInternalAuditorBypassesRestriction(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $section->addProfileRestriction($profile);
        $auditor = $this->teacher('auditor');
        $centre->getInternalAuditors()->add($auditor);
        $this->persist($centre, $section, $profile, $auditor);

        self::assertTrue($this->access->canViewSection($auditor, $section));
    }

    public function testCanViewSectionAdminBypassesRestriction(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $section->addProfileRestriction($profile);
        $admin = $this->teacher('admin');
        $admin->setAdmin(true);
        $this->persist($centre, $section, $profile, $admin);

        self::assertTrue($this->access->canViewSection($admin, $section));
    }

    // ── canViewFolder / canViewDocument (no cascade at the folder level, but a document depends on every ancestor section too) ──

    public function testCanViewFolderOpenByDefault(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);

        self::assertTrue($this->access->canViewFolder($teacher, $folder));
    }

    public function testCanViewFolderDeniedWhenRestrictedAndTeacherLacksProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addVisibilityProfile($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher);

        self::assertFalse($this->access->canViewFolder($teacher, $folder));
    }

    /**
     * canViewDocument must check every ancestor section independently, since restrictions never
     * cascade: a section's own restriction is bypassable, but a stricter grandparent section still
     * blocks the document even when the immediate parent section and the folder itself are open.
     */
    public function testCanViewDocumentDeniedWhenAnyAncestorSectionIsRestricted(): void
    {
        $centre       = $this->centre();
        $grandparent  = $this->section($centre, 'Grandparent');
        $parent       = $this->section($centre, 'Parent');
        $parent->setParent($grandparent);
        $folder = $this->folder($parent);

        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $grandparent->addProfileRestriction($profile);

        $document = new Document($folder, 'Doc');
        $teacher  = $this->teacher('docente');

        $this->persist($centre, $grandparent, $parent, $folder, $profile, $document, $teacher);

        self::assertTrue($this->access->canViewSection($teacher, $parent), 'the immediate parent has no restriction of its own');
        self::assertTrue($this->access->canViewFolder($teacher, $folder), 'the folder has no restriction of its own');
        self::assertFalse($this->access->canViewDocument($teacher, $document), 'but the grandparent restriction still blocks the document');
    }

    public function testCanViewDocumentGrantedWhenEveryAncestorAndFolderAllow(): void
    {
        $centre      = $this->centre();
        $grandparent = $this->section($centre, 'Grandparent');
        $parent      = $this->section($centre, 'Parent');
        $parent->setParent($grandparent);
        $folder = $this->folder($parent);

        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $grandparent->addProfileRestriction($profile);

        $document   = new Document($folder, 'Doc');
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $grandparent, $parent, $folder, $profile, $document, $teacher, $assignment);

        self::assertTrue($this->access->canViewDocument($teacher, $document));
    }

    // ── canManageFolder / canUploadToFolder / canReviewFolder ───────────────────

    public function testCanManageFolderGrantedByAdminOrQualityManagerOnly(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $admin   = $this->teacher('admin');
        $admin->setAdmin(true);
        $qm = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $auditor = $this->teacher('auditor');
        $centre->getInternalAuditors()->add($auditor);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin, $qm, $auditor);

        self::assertTrue($this->access->canManageFolder($admin, $folder));
        self::assertTrue($this->access->canManageFolder($qm, $folder));
        self::assertFalse($this->access->canManageFolder($auditor, $folder), 'an internal auditor can read but not manage');
    }

    public function testCanUploadToFolderGrantedByManagePrivilegeEvenWithoutAnUploadRow(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertTrue($this->access->canUploadToFolder($teacher, $folder));
    }

    public function testCanReviewFolderGrantedByManagePrivilegeEvenWithoutAReviewRow(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertTrue($this->access->canReviewFolder($teacher, $folder));
    }

    /**
     * Unlike canReviewFolder(), holdsReviewProfile() is never widened by canManageFolder()'s
     * admin/quality-manager bypass — it answers "is this personally assigned to me", which is what
     * PendingReviewFinder needs for the notification bell and the dashboard's personal widget.
     */
    public function testHoldsReviewProfileIsNotGrantedByManagePrivilegeAlone(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($this->section($centre));
        $qm     = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $qm);

        self::assertFalse($this->access->holdsReviewProfile($qm, $folder));
    }

    public function testHoldsReviewProfileIsTrueForATeacherPersonallyAssignedTheReviewProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile, null);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);

        self::assertTrue($this->access->holdsReviewProfile($teacher, $folder));
    }

    // ── canManageDocumentAsUploader ──────────────────────────────────────────

    public function testCanManageDocumentAsUploaderGrantedToActiveRevisionUploader(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($this->section($centre));
        $uploader = $this->teacher('subidor');
        $stranger = $this->teacher('otro');

        $document = new Document($folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $stranger, $document, $file, $revision);

        self::assertTrue($this->access->canManageDocumentAsUploader($uploader, $document));
        self::assertFalse($this->access->canManageDocumentAsUploader($stranger, $document));
    }

    public function testCanManageDocumentAsUploaderFalseWhenUploaderIsNotTheActiveRevisionsUploader(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($this->section($centre));
        $first  = $this->teacher('primero');
        $second = $this->teacher('segundo');

        $document = new Document($folder, 'Doc');
        $file1    = new DocumentFile(hash('sha256', '1'), '1', 'text/plain', 'f1.txt', 1);
        $rev1     = new DocumentRevision($document, 1, $file1, false, $first);
        $document->getRevisions()->add($rev1);
        $document->setActiveRevision($rev1);

        $file2 = new DocumentFile(hash('sha256', '2'), '2', 'text/plain', 'f2.txt', 1);
        $rev2  = new DocumentRevision($document, 2, $file2, false, $second);
        $document->getRevisions()->add($rev2);
        $document->setActiveRevision($rev2);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $first, $second, $document, $file1, $rev1, $file2, $rev2);

        // $first uploaded the document but is no longer the active revision's uploader.
        self::assertFalse($this->access->canManageDocumentAsUploader($first, $document));
        self::assertTrue($this->access->canManageDocumentAsUploader($second, $document));
    }

    public function testCanManageDocumentAsUploaderGrantedToFolderManagerRegardlessOfUploader(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($this->section($centre));
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $manager    = $this->teacher('responsable');
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $uploader   = $this->teacher('subidor');

        $document = new Document($folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $manager, $assignment, $uploader, $document, $file, $revision);

        self::assertTrue($this->access->canManageDocumentAsUploader($manager, $document));
    }

    // ── holdsProfile ──────────────────────────────────────────────────────────

    public function testHoldsProfileDistinguishesDifferentSubperfilesOfTheSameProfile(): void
    {
        $centre    = $this->centre();
        $subperfil = (new ListItem())->setEducationalCentre($centre)->setName('Subperfil');
        $otherLeaf = (new ListItem())->setEducationalCentre($centre)->setName('Otra hoja');
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subperfil);
        $teacher   = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, $subperfil, $teacher);

        $this->persist($centre, $subperfil, $otherLeaf, $profile, $teacher, $assignment);

        self::assertTrue($this->access->holdsProfile($teacher, $profile, $subperfil));
        self::assertFalse($this->access->holdsProfile($teacher, $profile, $otherLeaf));
    }

    public function testHoldsProfileFalseForATeacherWithNoAssignmentAtAll(): void
    {
        $centre  = $this->centre();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $teacher = $this->teacher('docente');
        $this->persist($centre, $profile, $teacher);

        self::assertFalse($this->access->holdsProfile($teacher, $profile, null));
    }

    /**
     * holdsProfile() memoizes its result per (teacher, profile, list item) for the life of the
     * service — calling it repeatedly (as a loop over many activities/documents does) must never
     * mix up results between different teachers, even when checking the very same profile.
     */
    public function testHoldsProfileMemoizesRepeatedCallsWithoutMixingUpDifferentTeachers(): void
    {
        $centre     = $this->centre();
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $holder     = $this->teacher('con-perfil');
        $outsider   = $this->teacher('sin-perfil');
        $assignment = new SpecificProfileAssignment($profile, null, $holder);
        $this->persist($centre, $profile, $holder, $outsider, $assignment);

        self::assertTrue($this->access->holdsProfile($holder, $profile, null));
        self::assertTrue($this->access->holdsProfile($holder, $profile, null));
        self::assertFalse($this->access->holdsProfile($outsider, $profile, null));
        self::assertFalse($this->access->holdsProfile($outsider, $profile, null));
    }

    // ── getFolderUploadRows: wildcard expansion into concrete subperfil rows ────

    public function testGetFolderUploadRowsExpandsAWildcardIntoEachConcreteLeaf(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($this->section($centre));

        $root  = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $leafA = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $leafB = (new ListItem())->setEducationalCentre($centre)->setName('Lengua');
        $leafA->setParent($root);
        $leafB->setParent($root);

        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($root);
        $folder->addUploadProfile($profile, null); // wildcard restriction: any subperfil of this profile

        $this->persist($centre, $folder->getDocumentSection(), $folder, $root, $leafA, $leafB, $profile);

        $rows = $this->access->getFolderUploadRows($folder);

        self::assertCount(2, $rows, 'the wildcard row itself is never returned, only its concrete leaves');
        $listItemIds = array_map(static fn ($row) => $row->listItem?->getId()->toRfc4122(), $rows);
        self::assertContains($leafA->getId()->toRfc4122(), $listItemIds);
        self::assertContains($leafB->getId()->toRfc4122(), $listItemIds);
        foreach ($rows as $row) {
            self::assertSame($profile, $row->profile);
        }
    }

    public function testGetFolderUploadRowsReturnsPlainProfileRowUnchanged(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($this->section($centre));
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile);

        $rows = $this->access->getFolderUploadRows($folder);

        self::assertCount(1, $rows);
        self::assertSame($profile, $rows[0]->profile);
        self::assertNull($rows[0]->listItem);
    }

    // ── isActivityRelevantToTeacher ───────────────────────────────────────────

    public function testActivityWithoutFolderIsAlwaysRelevant(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        self::assertTrue($this->access->isActivityRelevantToTeacher($teacher, $activity));
    }

    public function testActivityWithFolderIsRelevantOnlyToManagersUploadersOrReviewers(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($this->section($centre));
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Subidor');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6)->setFolder($folder);

        $uploader   = $this->teacher('subidor');
        $assignment = new SpecificProfileAssignment($profile, null, $uploader);
        $stranger   = $this->teacher('ajeno');

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $uploader, $assignment, $stranger);

        self::assertTrue($this->access->isActivityRelevantToTeacher($uploader, $activity));
        self::assertFalse($this->access->isActivityRelevantToTeacher($stranger, $activity));
    }
}
