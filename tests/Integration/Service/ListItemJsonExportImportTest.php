<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\ListItemRepository;
use App\Service\ListItemJsonExporter;
use App\Service\ListItemJsonImporter;
use App\Tests\Integration\RepositoryTestCase;

final class ListItemJsonExportImportTest extends RepositoryTestCase
{
    private ListItemJsonExporter $exporter;
    private ListItemJsonImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ListItemJsonExporter $exporter */
        $exporter       = self::getContainer()->get(ListItemJsonExporter::class);
        $this->exporter = $exporter;

        /** @var ListItemJsonImporter $importer */
        $importer       = self::getContainer()->get(ListItemJsonImporter::class);
        $this->importer = $importer;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    public function testRoundTripPreservesTreeShape(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A')->setPosition(0);
        $child->setParent($root);

        $this->persist($centre, $root, $child);

        $exported = $this->exporter->export($centre);
        self::assertSame('list_items', $exported['type']);

        $counts = $this->importer->import($exported, $centre);
        self::assertSame(2, $counts['items']);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Grupo', $roots[0]->getName());
        $children = $items->findChildrenByParent($roots[0]);
        self::assertCount(1, $children);
        self::assertSame('1º ESO A', $children[0]->getName());
    }

    public function testImportReplacesTheWholeExistingTree(): void
    {
        $centre  = $this->centre();
        $old     = (new ListItem())->setEducationalCentre($centre)->setName('Antigua');
        $this->persist($centre, $old);

        $payload = ['type' => 'list_items', 'items' => [
            ['name' => 'Nueva', 'active' => true, 'children' => []],
        ]];
        $this->importer->import($payload, $centre);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Nueva', $roots[0]->getName());
    }

    /** Import must be refused wholesale (nothing touched) when any EXISTING item is currently in use. */
    public function testImportRefusedWhenAnExistingItemIsInUse(): void
    {
        $centre  = $this->centre();
        $inUse   = (new ListItem())->setEducationalCentre($centre)->setName('En uso');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil')->setListItem($inUse);
        $this->persist($centre, $inUse, $profile);

        $payload = ['type' => 'list_items', 'items' => [
            ['name' => 'Nueva', 'active' => true, 'children' => []],
        ]];

        $this->expectException(\DomainException::class);
        $this->importer->import($payload, $centre);
    }

    public function testImportRejectsAnItemWithoutAName(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['type' => 'list_items', 'items' => [
            ['active' => true, 'children' => []],
        ]], $centre);
    }

    public function testImportRejectsAWrongType(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['type' => 'document_sections', 'items' => []], $centre);
    }

    /** Files exported while tags still existed carry a "tags" array per node: they still import, the tags are ignored. */
    public function testAnOlderFileCarryingTagsStillImportsAndTheTagsAreIgnored(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $payload = ['type' => 'list_items', 'items' => [
            ['name' => 'A', 'active' => true, 'tags' => ['ESO'], 'children' => []],
            ['name' => 'B', 'active' => true, 'tags' => 'cualquier cosa', 'children' => []],
        ]];
        $counts = $this->importer->import($payload, $centre);

        self::assertSame(2, $counts['items']);
        self::assertArrayNotHasKey('tags', $counts);
    }

    /** A tree nested deeper than any real one is refused before anything is touched. */
    public function testImportRejectsATreeNestedTooDeep(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $children = [];
        for ($i = 0; $i < 21; ++$i) {
            $children = [['name' => 'Nivel', 'active' => true, 'children' => $children]];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nested more than 20 levels');
        $this->importer->import(['type' => 'list_items', 'items' => $children], $centre);
    }

    public function testImportAcceptsATreeTwentyLevelsDeep(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $children = [];
        for ($i = 0; $i < 20; ++$i) {
            $children = [['name' => 'Nivel', 'active' => true, 'children' => $children]];
        }

        $this->importer->import(['type' => 'list_items', 'items' => $children], $centre);
        $this->addToAssertionCount(1);
    }
}
