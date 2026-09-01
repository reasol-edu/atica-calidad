<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

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
use App\Model\SenecaImportNode;
use App\Repository\DocumentRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;
use App\Service\SenecaListImporter;
use App\Tests\Integration\RepositoryTestCase;

final class SenecaListImporterTest extends RepositoryTestCase
{
    private SenecaListImporter $importer;
    private ListItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SenecaListImporter $importer */
        $importer       = self::getContainer()->get(SenecaListImporter::class);
        $this->importer = $importer;

        /** @var ListItemRepository $items */
        $items       = self::getContainer()->get(ListItemRepository::class);
        $this->items = $items;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /**
     * A document uploaded under $item, so isListItemUsedByDocument($item) is true.
     * Document::setUploadProfile() forces uploadListItem back to null when the profile itself is
     * null (uploadListItem is only ever meaningful alongside a profile), so a plain SpecificProfile
     * not otherwise associated with $item is needed here purely to carry that tagging.
     */
    private function document(EducationalCentre $centre, ListItem $item, Teacher $uploader): Document
    {
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil de entrega');
        $document = new Document($folder, 'Entrega');
        $document->setUploadProfile($profile, $item);
        $this->em->persist($profile);
        $file     = new DocumentFile(hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'Entrega', 'text/plain', 'entrega.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->em->persist($section);
        $this->em->persist($folder);
        $this->em->persist($file);
        $this->em->persist($document);
        $this->em->persist($revision);

        return $document;
    }

    // ── plan() / apply() basics: root creation vs. reuse ─────────────────────

    public function testApplyCreatesTheRootWhenItDoesNotExistYet(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $desired = [new SenecaImportNode('1º DAM'), new SenecaImportNode('2º DAM')];
        $plan    = $this->importer->plan($centre, 'Grupo', $desired);

        self::assertFalse($plan->rootExists);
        self::assertCount(2, $plan->additions);

        $counts = $this->importer->apply($centre, 'Grupo', $desired, true);
        self::assertSame(['added' => 2, 'deleted' => 0, 'deactivated' => 0, 'reactivated' => 0], $counts);

        $this->em->clear();
        $roots = $this->items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Grupo', $roots[0]->getName());
        $children = $this->items->findChildrenByParent($roots[0]);
        self::assertSame(['1º DAM', '2º DAM'], array_map(static fn (ListItem $i): string => $i->getName(), $children));
    }

    public function testApplyReusesAnExistingRootByCaseInsensitiveName(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('grupo')->setPosition(0);
        $this->persist($centre, $root);

        $plan = $this->importer->plan($centre, 'Grupo', [new SenecaImportNode('1º DAM')]);
        self::assertTrue($plan->rootExists);

        $this->importer->apply($centre, 'Grupo', [new SenecaImportNode('1º DAM')], true);

        $this->em->clear();
        self::assertCount(1, $this->items->findRootsByCentre($centre), 'must not create a second root');
    }

    // ── nested subjects tree ──────────────────────────────────────────────────

    public function testSubjectsImportNestsSubjectsUnderTheirGroup(): void
    {
        $centre = $this->centre();
        $this->persist($centre);

        $group = new SenecaImportNode('1º DAM');
        $group->childNamed('Sistemas informáticos');
        $group->childNamed('Bases de datos');

        $this->importer->apply($centre, 'Materia', [$group], true);

        $this->em->clear();
        $roots = $this->items->findRootsByCentre($centre);
        $groupItem = $this->items->findChildrenByParent($roots[0])[0];
        self::assertSame('1º DAM', $groupItem->getName());
        $subjects = $this->items->findChildrenByParent($groupItem);
        self::assertSame(['Sistemas informáticos', 'Bases de datos'], array_map(static fn (ListItem $i): string => $i->getName(), $subjects));
    }

    // ── deletions of unused items ──────────────────────────────────────────────

    public function testUnusedExistingItemNotInTheImportIsDeletedByDefault(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $stale  = (new ListItem())->setEducationalCentre($centre)->setName('Grupo obsoleto')->setPosition(0);
        $stale->setParent($root);
        $this->persist($centre, $root, $stale);

        $plan = $this->importer->plan($centre, 'Grupo', [new SenecaImportNode('1º DAM')]);
        self::assertCount(1, $plan->deletions);
        self::assertSame(['Grupo obsoleto'], $plan->deletions[0]->path);

        $counts = $this->importer->apply($centre, 'Grupo', [new SenecaImportNode('1º DAM')], true);
        self::assertSame(1, $counts['deleted']);

        $this->em->clear();
        $roots    = $this->items->findRootsByCentre($centre);
        $children = $this->items->findChildrenByParent($roots[0]);
        self::assertSame(['1º DAM'], array_map(static fn (ListItem $i): string => $i->getName(), $children));
    }

    public function testDeleteUnusedFalseDeactivatesInsteadOfDeletingAnUnusedOrphan(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $stale  = (new ListItem())->setEducationalCentre($centre)->setName('Grupo obsoleto')->setPosition(0);
        $stale->setParent($root);
        $this->persist($centre, $root, $stale);

        $counts = $this->importer->apply($centre, 'Grupo', [new SenecaImportNode('1º DAM')], false);
        self::assertSame(0, $counts['deleted']);
        self::assertSame(1, $counts['deactivated']);

        $this->em->clear();
        $roots    = $this->items->findRootsByCentre($centre);
        $children = $this->items->findChildrenByParent($roots[0]);
        $stillThere = array_values(array_filter($children, static fn (ListItem $i): bool => $i->getName() === 'Grupo obsoleto'));
        self::assertCount(1, $stillThere);
        self::assertFalse($stillThere[0]->isActive());
    }

    // ── forced deactivation of in-use items (all three "in use" reasons) ───────

    public function testAnOrphanUsedByASpecificProfileIsDeactivatedNeverDeleted(): void
    {
        $centre  = $this->centre();
        $root    = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $inUse   = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setPosition(0);
        $inUse->setParent($root);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutoría')->setListItem($inUse);
        $this->persist($centre, $root, $inUse, $profile);

        $plan = $this->importer->plan($centre, 'Grupo', []);
        self::assertCount(0, $plan->deletions);
        self::assertCount(1, $plan->deactivations);

        $counts = $this->importer->apply($centre, 'Grupo', [], true);
        self::assertSame(0, $counts['deleted']);
        self::assertSame(1, $counts['deactivated']);

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        self::assertTrue($profiles->isListItemInUse($inUse));
    }

    public function testAnOrphanUsedByASpecificProfileAssignmentIsDeactivatedNeverDeleted(): void
    {
        $centre  = $this->centre();
        $root    = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $inUse   = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setPosition(0);
        $inUse->setParent($root);
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutoría');
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, $inUse, $teacher);
        $this->persist($centre, $root, $inUse, $profile, $teacher, $assignment);

        $counts = $this->importer->apply($centre, 'Grupo', [], true);
        self::assertSame(0, $counts['deleted']);
        self::assertSame(1, $counts['deactivated']);

        $this->em->clear();
        /** @var SpecificProfileAssignmentRepository $assignments */
        $assignments = self::getContainer()->get(SpecificProfileAssignmentRepository::class);
        self::assertTrue($assignments->isListItemAssigned($inUse));
    }

    public function testAnOrphanUsedByADeliveredDocumentIsDeactivatedNeverDeleted(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $inUse  = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setPosition(0);
        $inUse->setParent($root);
        $teacher  = $this->teacher('docente');
        $document = $this->document($centre, $inUse, $teacher);
        $this->persist($centre, $root, $inUse, $teacher, $document);

        $counts = $this->importer->apply($centre, 'Grupo', [], true);
        self::assertSame(0, $counts['deleted']);
        self::assertSame(1, $counts['deactivated']);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $items     = $this->items->findAllByCentre($centre);
        $reloaded  = current(array_filter($items, static fn (ListItem $i): bool => $i->getName() === '1º DAM'));
        self::assertNotFalse($reloaded);
        self::assertTrue($documents->isListItemUsedByDocument($reloaded));
    }

    /** An in-use leaf must keep its whole ancestor chain around too (deactivated, not deleted). */
    public function testAnInUseLeafProtectsItsWholeAncestorChainFromDeletion(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Materia')->setPosition(0);
        $group  = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setPosition(0);
        $group->setParent($root);
        $subject = (new ListItem())->setEducationalCentre($centre)->setName('Programación')->setPosition(0);
        $subject->setParent($group);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subject);
        $this->persist($centre, $root, $group, $subject, $profile);

        // Nothing in the new import mentions "1º DAM" or "Programación" any more.
        $counts = $this->importer->apply($centre, 'Materia', [], true);

        self::assertSame(0, $counts['deleted'], 'the group must survive too, since its child is in use');
        self::assertSame(2, $counts['deactivated'], 'both the group and the subject get deactivated');

        $this->em->clear();
        $roots = $this->items->findRootsByCentre($centre);
        $groupChildren = $this->items->findChildrenByParent($roots[0]);
        self::assertCount(1, $groupChildren, 'the group item itself must still exist');
        self::assertFalse($groupChildren[0]->isActive());
    }

    // ── reactivation ─────────────────────────────────────────────────────────

    public function testAPreviouslyInactiveItemThatReappearsIsReactivated(): void
    {
        $centre   = $this->centre();
        $root     = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $inactive = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setPosition(0)->setActive(false);
        $inactive->setParent($root);
        $this->persist($centre, $root, $inactive);

        $plan = $this->importer->plan($centre, 'Grupo', [new SenecaImportNode('1º DAM')]);
        self::assertCount(1, $plan->reactivations);

        $counts = $this->importer->apply($centre, 'Grupo', [new SenecaImportNode('1º DAM')], true);
        self::assertSame(1, $counts['reactivated']);

        $this->em->clear();
        $roots    = $this->items->findRootsByCentre($centre);
        $children = $this->items->findChildrenByParent($roots[0]);
        self::assertTrue($children[0]->isActive());
    }

    // ── no-op ────────────────────────────────────────────────────────────────

    public function testPlanIsEmptyWhenTheImportAlreadyMatchesTheExistingList(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setPosition(0);
        $item->setParent($root);
        $this->persist($centre, $root, $item);

        $plan = $this->importer->plan($centre, 'Grupo', [new SenecaImportNode('1º DAM')]);

        self::assertTrue($plan->isEmpty());
    }
}
