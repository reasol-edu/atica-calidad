<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Service\DocumentTreeAccessChecker;
use App\Tests\Integration\RepositoryTestCase;

/**
 * Covers the two pieces added for document search: DocumentRepository::searchByCentre() (a plain
 * name match across the whole tree, unfiltered) and DocumentTreeAccessChecker::canViewDocument()
 * (the access filter search results are run through), for both a folder-level and a
 * section-level restriction — the two never cascade into each other, so a document can be blocked
 * by either independently.
 */
final class DocumentSearchTest extends RepositoryTestCase
{
    public function testSearchByCentreMatchesByPartialCaseInsensitiveName(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $teacher = (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername('docente');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);

        $matching    = new Document($folder, 'Acta de la reunión de mayo');
        $notMatching = new Document($folder, 'Memoria anual');

        $this->persist($centre, $teacher, $section, $folder, $matching, $notMatching);

        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);

        $results = $documents->searchByCentre($centre, 'ACTA');
        self::assertCount(1, $results);
        self::assertSame($matching->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());

        self::assertCount(0, $documents->searchByCentre($centre, 'inexistente'));
    }

    public function testCanViewDocumentRespectsFolderAndSectionRestrictionsIndependently(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');

        $coordProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Coordinador')->setPosition(0);

        $plainTeacher       = (new Teacher(new PersonName('Sin', 'Perfil')))->setUsername('sinperfil');
        $coordinatorTeacher = (new Teacher(new PersonName('Con', 'Perfil')))->setUsername('conperfil');

        $openSection = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección abierta')->setPosition(0);

        $restrictedSection = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección restringida')->setPosition(1);
        $restrictedSection->addProfileRestriction($coordProfile, null);

        $openFolder = (new Folder())->setDocumentSection($openSection)->setName('Carpeta abierta')->setPosition(0);

        $folderRestrictedByItself = (new Folder())->setDocumentSection($openSection)->setName('Carpeta restringida')->setPosition(1);
        $folderRestrictedByItself->addVisibilityProfile($coordProfile, null);

        $folderInsideRestrictedSection = (new Folder())->setDocumentSection($restrictedSection)->setName('Carpeta sin restricción propia')->setPosition(0);

        $docInOpenFolder         = new Document($openFolder, 'Doc abierto');
        $docInFolderRestriction  = new Document($folderRestrictedByItself, 'Doc restringido por carpeta');
        $docInSectionRestriction = new Document($folderInsideRestrictedSection, 'Doc restringido por sección');

        $this->persist(
            $centre,
            $coordProfile,
            $plainTeacher,
            $coordinatorTeacher,
            $openSection,
            $restrictedSection,
            $openFolder,
            $folderRestrictedByItself,
            $folderInsideRestrictedSection,
            $docInOpenFolder,
            $docInFolderRestriction,
            $docInSectionRestriction,
        );
        $this->persist(new SpecificProfileAssignment($coordProfile, null, $coordinatorTeacher));

        /** @var DocumentTreeAccessChecker $access */
        $access = self::getContainer()->get(DocumentTreeAccessChecker::class);

        self::assertTrue($access->canViewDocument($plainTeacher, $docInOpenFolder));

        self::assertFalse($access->canViewDocument($plainTeacher, $docInFolderRestriction));
        self::assertTrue($access->canViewDocument($coordinatorTeacher, $docInFolderRestriction));

        // The folder itself has no restriction, but its section does — must still be blocked.
        self::assertFalse($access->canViewDocument($plainTeacher, $docInSectionRestriction));
        self::assertTrue($access->canViewDocument($coordinatorTeacher, $docInSectionRestriction));
    }
}
