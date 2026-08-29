<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\DocumentSectionRepository;
use App\Service\DocumentSectionJsonExporter;
use App\Service\DocumentSectionJsonImporter;
use App\Tests\Integration\RepositoryTestCase;

final class DocumentSectionJsonExportImportTest extends RepositoryTestCase
{
    private DocumentSectionJsonExporter $exporter;
    private DocumentSectionJsonImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DocumentSectionJsonExporter $exporter */
        $exporter       = self::getContainer()->get(DocumentSectionJsonExporter::class);
        $this->exporter = $exporter;

        /** @var DocumentSectionJsonImporter $importer */
        $importer       = self::getContainer()->get(DocumentSectionJsonImporter::class);
        $this->importer = $importer;
    }

    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    public function testRoundTripPreservesTreeShapeAndDirectProfileRestrictions(): void
    {
        $centre = $this->centre();
        $root   = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Calidad')->setPosition(0);
        $child  = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Procedimientos')->setPosition(0);
        $child->setParent($root);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $child->addProfileRestriction($profile, null);

        $this->persist($centre, $root, $child, $profile);

        $exported = $this->exporter->export($centre);
        self::assertSame('document_sections', $exported['type']);

        $counts = $this->importer->import($exported, $centre);
        self::assertSame(2, $counts['sections']);
        self::assertSame(0, $counts['skippedProfiles']);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $roots    = $sections->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Calidad', $roots[0]->getName());
        $children = $sections->findChildrenByParent($roots[0]);
        self::assertCount(1, $children);
        self::assertSame('Procedimientos', $children[0]->getName());
        self::assertTrue($children[0]->isRestricted());
    }

    public function testRoundTripPreservesAListAssociatedSubperfilRestrictionByPath(): void
    {
        $centre    = $this->centre();
        $section   = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $departamentos = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos');
        $matematicas   = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas');
        $matematicas->setParent($departamentos);
        $jefatura = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($departamentos);
        $section->addProfileRestriction($jefatura, $matematicas);

        $this->persist($centre, $section, $departamentos, $matematicas, $jefatura);

        $exported = $this->exporter->export($centre);
        $counts   = $this->importer->import($exported, $centre);

        self::assertSame(1, $counts['sections']);
        self::assertSame(0, $counts['skippedProfiles']);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $roots    = $sections->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertTrue($roots[0]->isRestricted());
        $restriction = $roots[0]->getProfileRestrictions()->first();
        self::assertNotFalse($restriction);
        $listItem = $restriction->getListItem();
        self::assertNotNull($listItem);
        self::assertSame('Matemáticas', $listItem->getName());
    }

    /** Import is a full replace: whatever existed in the centre before must be gone afterward. */
    public function testImportReplacesTheWholeExistingTree(): void
    {
        $centre = $this->centre();
        $old    = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Antigua');
        $this->persist($centre, $old);

        $payload = ['type' => 'document_sections', 'sections' => [
            ['name' => 'Nueva', 'profiles' => [], 'children' => []],
        ]];
        $this->importer->import($payload, $centre);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $roots    = $sections->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Nueva', $roots[0]->getName());
    }

    /** A profile name that no longer exists in the centre is skipped and counted, not fatal to the rest of the import. */
    public function testImportSkipsAndCountsAnUnknownProfileNameWithoutFailing(): void
    {
        $centre  = $this->centre();
        $payload = ['type' => 'document_sections', 'sections' => [
            [
                'name'     => 'Sección',
                'profiles' => [['profile' => 'Perfil que ya no existe', 'listItemPath' => null]],
                'children' => [],
            ],
        ]];
        $this->persist($centre);

        $counts = $this->importer->import($payload, $centre);

        self::assertSame(1, $counts['sections']);
        self::assertSame(1, $counts['skippedProfiles']);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $roots    = $sections->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertFalse($roots[0]->isRestricted());
    }

    public function testImportRejectsAPayloadWithTheWrongType(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['type' => 'something_else', 'sections' => []], $centre);
    }

    public function testImportRejectsASectionWithoutAName(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['type' => 'document_sections', 'sections' => [
            ['profiles' => [], 'children' => []],
        ]], $centre);
    }

    public function testExportedJsonRoundTripsThroughRealJsonEncoding(): void
    {
        $centre = $this->centre();
        $root   = (new \App\Entity\DocumentSection())->setEducationalCentre($centre)->setName('Raíz');
        $this->persist($centre, $root);

        $exported = $this->exporter->export($centre);
        $json     = json_encode($exported, JSON_THROW_ON_ERROR);
        /** @var array<mixed, mixed> $decoded */
        $decoded  = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $counts = $this->importer->import($decoded, $centre);
        self::assertSame(1, $counts['sections']);
    }
}
