<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Tag;
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

    public function testRoundTripPreservesTreeShapeAndOwnTags(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A')->setPosition(0);
        $child->setParent($root);
        $tag = (new Tag())->setEducationalCentre($centre)->setName('ESO');
        $child->addTag($tag);

        $this->persist($centre, $root, $child, $tag);

        $exported = $this->exporter->export($centre);
        self::assertSame('list_items', $exported['type']);

        $counts = $this->importer->import($exported, $centre);
        self::assertSame(2, $counts['items']);
        self::assertSame(1, $counts['tags']);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Grupo', $roots[0]->getName());
        $children = $items->findChildrenByParent($roots[0]);
        self::assertCount(1, $children);
        self::assertSame('1º ESO A', $children[0]->getName());
        self::assertCount(1, $children[0]->getTags());
        $childTag = $children[0]->getTags()->first();
        self::assertNotFalse($childTag);
        self::assertSame('ESO', $childTag->getName());
    }

    public function testExportDoesNotSerializeInheritedTagsOnTheChild(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('Hijo');
        $child->setParent($root);
        $tag = (new Tag())->setEducationalCentre($centre)->setName('Etiqueta raíz');
        $root->addTag($tag);
        $this->persist($centre, $root, $child, $tag);

        $exported = $this->exporter->export($centre);

        /** @var array<int, array<string, mixed>> $rootNodes */
        $rootNodes = $exported['items'];
        self::assertSame(['Etiqueta raíz'], $rootNodes[0]['tags']);
        /** @var array<int, array<string, mixed>> $childNodes */
        $childNodes = $rootNodes[0]['children'];
        self::assertSame([], $childNodes[0]['tags'], "the child's own export must not duplicate the inherited tag — it's recomputed from the hierarchy on import");
    }

    public function testImportReplacesTheWholeExistingTreeAndItsTags(): void
    {
        $centre  = $this->centre();
        $old     = (new ListItem())->setEducationalCentre($centre)->setName('Antigua');
        $oldTag  = (new Tag())->setEducationalCentre($centre)->setName('Vieja');
        $old->addTag($oldTag);
        $this->persist($centre, $old, $oldTag);

        $payload = ['type' => 'list_items', 'items' => [
            ['name' => 'Nueva', 'active' => true, 'tags' => [], 'children' => []],
        ]];
        $this->importer->import($payload, $centre);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Nueva', $roots[0]->getName());
        /** @var \App\Repository\TagRepository $tags */
        $tags = self::getContainer()->get(\App\Repository\TagRepository::class);
        self::assertCount(0, $tags->findByCentre($centre));
    }

    /** Import must be refused wholesale (nothing touched) when any EXISTING item is currently in use. */
    public function testImportRefusedWhenAnExistingItemIsInUse(): void
    {
        $centre  = $this->centre();
        $inUse   = (new ListItem())->setEducationalCentre($centre)->setName('En uso');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil')->setListItem($inUse);
        $this->persist($centre, $inUse, $profile);

        $payload = ['type' => 'list_items', 'items' => [
            ['name' => 'Nueva', 'active' => true, 'tags' => [], 'children' => []],
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
            ['active' => true, 'tags' => [], 'children' => []],
        ]], $centre);
    }

    public function testImportRejectsAWrongType(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import(['type' => 'document_sections', 'items' => []], $centre);
    }

    public function testImportDeduplicatesTagsByCaseInsensitiveName(): void
    {
        $centre  = $this->centre();
        $this->persist($centre);

        $payload = ['type' => 'list_items', 'items' => [
            ['name' => 'A', 'active' => true, 'tags' => ['ESO'], 'children' => []],
            ['name' => 'B', 'active' => true, 'tags' => ['eso'], 'children' => []],
        ]];
        $counts = $this->importer->import($payload, $centre);

        self::assertSame(2, $counts['items']);
        self::assertSame(1, $counts['tags'], 'ESO and eso must resolve to the same tag');
    }
}
