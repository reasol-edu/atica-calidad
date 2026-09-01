<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

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
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Tests\Integration\RepositoryTestCase;

final class DocumentRepositoryTest extends RepositoryTestCase
{
    private DocumentRepository $documents;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DocumentRepository $documents */
        $documents       = self::getContainer()->get(DocumentRepository::class);
        $this->documents = $documents;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** A ready-made document with a version-1 revision uploaded by $uploader, optionally tagged. */
    private function document(
        Folder $folder,
        string $name,
        ?Teacher $uploader,
        ?SpecificProfile $profile = null,
        ?ListItem $listItem = null,
    ): Document {
        $document = new Document($folder, $name);
        $document->setUploadProfile($profile, $listItem);
        $file     = new DocumentFile(hash('sha256', $name . random_int(1, PHP_INT_MAX)), $name, 'text/plain', $name . '.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader ?? $this->teacher('nadie'));
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->em->persist($file);
        $this->em->persist($document);
        $this->em->persist($revision);

        return $document;
    }

    // ── findOneByFolderProfileListItemNameAndFirstUploader (resolveSlot's matcher) ──────────────

    public function testFindsByProfileAndListItemExactly(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $teacher = $this->teacher('docente');

        $document = $this->document($folder, 'Entrega', $teacher, $profile, $item);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $item, $teacher, $document);

        $found = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, $item, 'Entrega', null);

        self::assertNotNull($found);
        self::assertSame($document->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }

    public function testNullProfileMustMatchExactlyNotAny(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $teacher = $this->teacher('docente');

        // A document tagged WITH a profile must not be found by a query for the untagged (null) case.
        $tagged = $this->document($folder, 'Entrega', $teacher, $profile, null);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $tagged);

        $found = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, null, null, 'Entrega', null);

        self::assertNull($found);
    }

    public function testNullListItemMustMatchExactlyNotAny(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $teacher = $this->teacher('docente');

        $tagged = $this->document($folder, 'Entrega', $teacher, $profile, $item);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $item, $teacher, $tagged);

        // Same profile, but querying for the "(todos)"/no-subprofile case must not match a
        // document tagged with a specific subprofile.
        $found = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, null, 'Entrega', null);

        self::assertNull($found);
    }

    public function testDistinguishesByNameWithinTheSameFolderAndProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $teacher = $this->teacher('docente');

        $matemáticas = $this->document($folder, 'Matemáticas', $teacher, $profile, null);
        $lengua      = $this->document($folder, 'Lengua', $teacher, $profile, null);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $matemáticas, $lengua);

        $found = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, null, 'Lengua', null);

        self::assertNotNull($found);
        self::assertSame($lengua->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }

    /**
     * Individual submission scope: two teachers holding the exact same profile/subprofile each get
     * their own Document for the same expected-name slot, distinguished only by who created the
     * first (version 1) revision — resolveSlot() must find the right one per teacher.
     */
    public function testFirstUploaderDistinguishesTwoDocumentsWithTheSameProfileListItemAndName(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A');
        $teacherA = $this->teacher('sergio');
        $teacherB = $this->teacher('javier');

        $docA = $this->document($folder, '1º ESO A', $teacherA, $profile, $item);
        $docB = $this->document($folder, '1º ESO A', $teacherB, $profile, $item);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $item, $teacherA, $teacherB, $docA, $docB);

        $foundA = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, $item, '1º ESO A', $teacherA);
        $foundB = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, $item, '1º ESO A', $teacherB);

        self::assertNotNull($foundA);
        self::assertNotNull($foundB);
        self::assertSame($docA->getId()->toRfc4122(), $foundA->getId()->toRfc4122());
        self::assertSame($docB->getId()->toRfc4122(), $foundB->getId()->toRfc4122());
        self::assertNotSame($foundA->getId()->toRfc4122(), $foundB->getId()->toRfc4122());
    }

    public function testFirstUploaderReturnsNullWhenNoDocumentWasFirstUploadedByThatTeacher(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $creator = $this->teacher('sergio');
        $someoneElse = $this->teacher('javier');

        $doc = $this->document($folder, 'Entrega', $creator, $profile, null);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $creator, $someoneElse, $doc);

        $found = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, null, 'Entrega', $someoneElse);

        self::assertNull($found);
    }

    /**
     * The uploader of the FIRST revision determines identity, not whoever uploaded the current
     * (possibly later) revision — an admin correcting/replacing the file afterwards must not make
     * the document "switch owners" from resolveSlot()'s point of view.
     */
    public function testFirstUploaderChecksVersionOneSpecificallyNotTheLatestRevision(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $original = $this->teacher('sergio');
        $replacement = $this->teacher('admin');

        $document = new Document($folder, 'Entrega');
        $document->setUploadProfile($profile, null);
        $file1 = new DocumentFile(hash('sha256', 'v1'), 'v1', 'text/plain', 'v1.txt', 1);
        $rev1  = new DocumentRevision($document, 1, $file1, false, $original);
        $document->getRevisions()->add($rev1);
        $document->setActiveRevision($rev1);

        $file2 = new DocumentFile(hash('sha256', 'v2'), 'v2', 'text/plain', 'v2.txt', 1);
        $rev2  = new DocumentRevision($document, 2, $file2, false, $replacement);
        $document->getRevisions()->add($rev2);
        $document->setActiveRevision($rev2);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $original, $replacement, $document, $file1, $rev1, $file2, $rev2);

        $foundByOriginal    = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, null, 'Entrega', $original);
        $foundByReplacement = $this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folder, $profile, null, 'Entrega', $replacement);

        self::assertNotNull($foundByOriginal);
        self::assertSame($document->getId()->toRfc4122(), $foundByOriginal->getId()->toRfc4122());
        self::assertNull($foundByReplacement, 'whoever uploaded a later revision is not the "first uploader"');
    }

    public function testMatchIsScopedToTheGivenFolder(): void
    {
        $centre   = $this->centre();
        $folderA  = $this->folder($centre);
        $folderB  = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $teacher  = $this->teacher('docente');

        $docInA = $this->document($folderA, 'Entrega', $teacher, $profile, null);
        $this->persist($centre, $folderA->getDocumentSection(), $folderA, $folderB->getDocumentSection(), $folderB, $profile, $teacher, $docInA);

        self::assertNull($this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folderB, $profile, null, 'Entrega', null));
        self::assertNotNull($this->documents->findOneByFolderProfileListItemNameAndFirstUploader($folderA, $profile, null, 'Entrega', null));
    }

    // ── searchActivitySubmissionsByCentre ────────────────────────────────────

    public function testSearchActivitySubmissionsOnlyMatchesDocumentsInAnActivityLinkedFolder(): void
    {
        $centre = $this->centre();

        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activityFolder = $this->folder($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6)->setFolder($activityFolder);

        $plainFolder = $this->folder($centre);

        $teacher = $this->teacher('docente');
        $submission  = $this->document($activityFolder, 'Programación de Matemáticas', $teacher);
        $plainDoc    = $this->document($plainFolder, 'Programación de Matemáticas', $teacher);

        $this->persist(
            $centre, $category, $activityFolder->getDocumentSection(), $activityFolder, $activity,
            $plainFolder->getDocumentSection(), $plainFolder, $teacher, $submission, $plainDoc,
        );

        $results = $this->documents->searchActivitySubmissionsByCentre($centre, 'Matemáticas');

        $ids = array_map(static fn (Document $d): string => $d->getId()->toRfc4122(), $results);
        self::assertContains($submission->getId()->toRfc4122(), $ids);
        self::assertNotContains($plainDoc->getId()->toRfc4122(), $ids);
    }

    public function testSearchActivitySubmissionsIsScopedToTheCentre(): void
    {
        $centreA = $this->centre();
        $centreB = (new EducationalCentre())->setCode('87654321')->setName('Otro centro')->setCity('Otra ciudad');

        $categoryA = (new ActivityCategory())->setEducationalCentre($centreA)->setName('Categoría A');
        $folderA   = $this->folder($centreA);
        $activityA = (new Activity())->setCategory($categoryA)->setTitle('Actividad A')->setStart(1, 9)->setEnd(30, 6)->setFolder($folderA);

        $categoryB = (new ActivityCategory())->setEducationalCentre($centreB)->setName('Categoría B');
        $folderB   = $this->folder($centreB);
        $activityB = (new Activity())->setCategory($categoryB)->setTitle('Actividad B')->setStart(1, 9)->setEnd(30, 6)->setFolder($folderB);

        $teacher = $this->teacher('docente');
        $docA = $this->document($folderA, 'Entrega compartida', $teacher);
        $docB = $this->document($folderB, 'Entrega compartida', $teacher);

        $this->persist(
            $centreA, $categoryA, $folderA->getDocumentSection(), $folderA, $activityA,
            $centreB, $categoryB, $folderB->getDocumentSection(), $folderB, $activityB,
            $teacher, $docA, $docB,
        );

        $results = $this->documents->searchActivitySubmissionsByCentre($centreA, 'Entrega');

        $ids = array_map(static fn (Document $d): string => $d->getId()->toRfc4122(), $results);
        self::assertContains($docA->getId()->toRfc4122(), $ids);
        self::assertNotContains($docB->getId()->toRfc4122(), $ids);
    }

    public function testSearchByCentreOrFolderNameMatchesByTheDocumentsOwnName(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher('docente');
        $matching = $this->document($folder, 'Norma de convivencia', $teacher);
        $other    = $this->document($folder, 'Otra cosa', $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher, $matching, $other);

        $ids = array_map(
            static fn (Document $d): string => $d->getId()->toRfc4122(),
            $this->documents->searchByCentreOrFolderName($centre, 'convivencia'),
        );

        self::assertContains($matching->getId()->toRfc4122(), $ids);
        self::assertNotContains($other->getId()->toRfc4122(), $ids);
    }

    public function testSearchByCentreOrFolderNameMatchesByTheFoldersName(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $folder->setName('Normativa de convivencia');
        $teacher  = $this->teacher('docente');
        $document = $this->document($folder, 'Documento sin relación con la búsqueda', $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher, $document);

        $ids = array_map(
            static fn (Document $d): string => $d->getId()->toRfc4122(),
            $this->documents->searchByCentreOrFolderName($centre, 'convivencia'),
        );

        self::assertContains($document->getId()->toRfc4122(), $ids);
    }

    public function testSearchByCentreOrFolderNameIsScopedToTheCentre(): void
    {
        $centreA = $this->centre();
        $centreB = (new EducationalCentre())->setCode('87654321')->setName('Otro centro')->setCity('Otra ciudad');
        $folderA = $this->folder($centreA);
        $folderB = $this->folder($centreB);
        $teacher = $this->teacher('docente');
        $docA    = $this->document($folderA, 'Documento compartido', $teacher);
        $docB    = $this->document($folderB, 'Documento compartido', $teacher);
        $this->persist(
            $centreA, $folderA->getDocumentSection(), $folderA,
            $centreB, $folderB->getDocumentSection(), $folderB,
            $teacher, $docA, $docB,
        );

        $ids = array_map(
            static fn (Document $d): string => $d->getId()->toRfc4122(),
            $this->documents->searchByCentreOrFolderName($centreA, 'compartido'),
        );

        self::assertContains($docA->getId()->toRfc4122(), $ids);
        self::assertNotContains($docB->getId()->toRfc4122(), $ids);
    }
}
