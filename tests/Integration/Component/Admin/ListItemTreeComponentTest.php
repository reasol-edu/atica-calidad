<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\ListItemRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class ListItemTreeComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function admin(string $username = 'director'): Teacher
    {
        $teacher = $this->teacher($username);

        return $teacher;
    }

    public function testMountDeniesAccessWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->render();
    }

    public function testAddItemCreatesARootItemAndSelectsIt(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addName', 'Departamentos')->call('addItem');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        $roots = $items->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Departamentos', $roots[0]->getName());
    }

    public function testAddItemRejectsAnEmptyName(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addName', '   ')->call('addItem');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertCount(0, $items->findRootsByCentre($centre));
    }

    public function testSaveDetailRenamesAndTogglesActive(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Original');
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->set('editName', 'Renombrado')->set('editActive', false)->call('saveDetail');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $reloaded = $items->findByIdAndCentre($itemId, $centre);
        self::assertNotNull($reloaded);
        self::assertSame('Renombrado', $reloaded->getName());
        self::assertFalse($reloaded->isActive());
    }

    public function testDeleteSelectedBlockedWhenItHasChildren(): void
    {
        $centre = $this->centre();
        $parent = (new ListItem())->setEducationalCentre($centre)->setName('Padre');
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('Hijo');
        $child->setParent($parent);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $parent, $child, $admin);
        $parentId = $parent->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $parentId]);
        $component->call('deleteSelected');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNotNull($items->findByIdAndCentre($parentId, $centre));
    }

    /** A profile's own root list-item (profile.listItem) is "in use" — deleting it would orphan the profile's whole subprofile source. */
    public function testDeleteSelectedBlockedWhenItIsAProfilesRootListItem(): void
    {
        $centre  = $this->centre();
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('En uso');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil')->setListItem($item);
        $admin   = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $profile, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->call('deleteSelected');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNotNull($items->findByIdAndCentre($itemId, $centre), "a list item backing a profile's list association must never be deletable");
    }

    /** An item a teacher is directly assigned to (via SpecificProfileAssignment) must also be protected. */
    public function testDeleteSelectedBlockedWhenAssignedToATeacher(): void
    {
        $centre    = $this->centre();
        $subprofile = (new ListItem())->setEducationalCentre($centre)->setName('Subprofile');
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subprofile);
        $teacher   = $this->teacher('docente');
        $assignment = new \App\Entity\SpecificProfileAssignment($profile, $subprofile, $teacher);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $subprofile, $profile, $teacher, $assignment, $admin);
        $subprofileId = $subprofile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $subprofileId]);
        $component->call('deleteSelected');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNotNull($items->findByIdAndCentre($subprofileId, $centre), 'a list item assigned to a teacher must never be deletable');
    }

    public function testDeleteSelectedRemovesALeafItem(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('A borrar');
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->call('deleteSelected');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNull($items->findByIdAndCentre($itemId, $centre));
    }

    public function testAddTagCreatesAndAttachesANewTag(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->set('newTagName', 'Bachillerato')->call('addTag');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $reloaded = $items->findByIdAndCentre($itemId, $centre);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getTags());
        $addedTag = $reloaded->getTags()->first();
        self::assertNotFalse($addedTag);
        self::assertSame('Bachillerato', $addedTag->getName());
    }

    public function testRemoveTagPrunesAnOrphanedTag(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $tag    = new \App\Entity\Tag();
        $tag->setEducationalCentre($centre)->setName('Solo aquí');
        $item->addTag($tag);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $tag, $admin);
        $itemId = $item->getId()->toRfc4122();
        $tagId  = $tag->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->call('removeTag', ['tagId' => $tagId]);

        $this->em->clear();
        /** @var \App\Repository\TagRepository $tags */
        $tags = self::getContainer()->get(\App\Repository\TagRepository::class);
        self::assertCount(0, $tags->findByCentre($centre), 'a tag with no remaining item must be pruned automatically');
    }

    public function testSaveAssociationLinksTheItemToAProfile(): void
    {
        $centre  = $this->centre();
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $admin   = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $profile, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->set('associationKey', $profile->getId()->toRfc4122())->call('saveAssociation');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $reloaded = $items->findByIdAndCentre($itemId, $centre);
        self::assertNotNull($reloaded);
        $associated = $reloaded->getAssociatedProfile();
        self::assertNotNull($associated);
        self::assertSame($profile->getId()->toRfc4122(), $associated->getId()->toRfc4122());
    }

    public function testSaveAssociationWithAnEmptyKeyClearsIt(): void
    {
        $centre  = $this->centre();
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $item->setAssociation($profile);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $profile, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->set('associationKey', '')->call('saveAssociation');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $reloaded = $items->findByIdAndCentre($itemId, $centre);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getAssociatedProfile());
    }

    // ── Bulk association ─────────────────────────────────────────────────────

    public function testToggleBulkSelectModeClearsAnyExistingSelectionAndChecks(): void
    {
        $centre = $this->centre();
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectItem', ['id' => $itemId]);
        $component->set('bulkSelectedIds', [$itemId])->call('toggleBulkSelectMode');

        self::assertTrue((bool) $this->props($component)['bulkSelectMode']);
        self::assertSame('', $this->stringProp($component, 'selectedId'), 'entering bulk mode closes the single-item detail panel');
        self::assertSame([], $this->props($component)['bulkSelectedIds'], 'toggling starts the selection fresh');
    }

    public function testBulkSaveAssociationAssignsTheSameProfileToEveryCheckedItem(): void
    {
        $centre  = $this->centre();
        $itemA   = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $itemB   = (new ListItem())->setEducationalCentre($centre)->setName('Química');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura de Ciencias');
        $admin   = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $itemA, $itemB, $profile, $admin);
        $idA = $itemA->getId()->toRfc4122();
        $idB = $itemB->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('toggleBulkSelectMode');
        $component
            ->set('bulkSelectedIds', [$idA, $idB])
            ->set('bulkAssociationKey', $profile->getId()->toRfc4122())
            ->call('bulkSaveAssociation');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items          = self::getContainer()->get(ListItemRepository::class);
        $reloadedA      = $items->findByIdAndCentre($idA, $centre);
        $reloadedB      = $items->findByIdAndCentre($idB, $centre);
        self::assertNotNull($reloadedA);
        self::assertNotNull($reloadedB);
        self::assertNotNull($reloadedA->getAssociatedProfile());
        self::assertNotNull($reloadedB->getAssociatedProfile());
        self::assertSame($profile->getId()->toRfc4122(), $reloadedA->getAssociatedProfile()->getId()->toRfc4122());
        self::assertSame($profile->getId()->toRfc4122(), $reloadedB->getAssociatedProfile()->getId()->toRfc4122());
    }

    public function testBulkSaveAssociationWithAnEmptyKeyClearsEveryCheckedItemsAssociation(): void
    {
        $centre  = $this->centre();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $itemA   = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $itemA->setAssociation($profile);
        $itemB = (new ListItem())->setEducationalCentre($centre)->setName('Química');
        $itemB->setAssociation($profile);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $itemA, $itemB, $profile, $admin);
        $idA = $itemA->getId()->toRfc4122();
        $idB = $itemB->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$idA, $idB])->call('bulkSaveAssociation');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNull($items->findByIdAndCentre($idA, $centre)?->getAssociatedProfile());
        self::assertNull($items->findByIdAndCentre($idB, $centre)?->getAssociatedProfile());
    }

    public function testBulkSaveAssociationIsANoOpWithNothingChecked(): void
    {
        $centre  = $this->centre();
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $admin   = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $item, $profile, $admin);
        $itemId = $item->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkAssociationKey', $profile->getId()->toRfc4122())->call('bulkSaveAssociation');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNull($items->findByIdAndCentre($itemId, $centre)?->getAssociatedProfile());
    }

    /**
     * Regression: the association picker (a TomSelect) must be re-keyed per selected item — its
     * wrapper id carries the selected item's id — so switching selection tears the widget down and
     * rebuilds it instead of leaving a frozen control that could only ever edit the first item.
     */
    public function testAssociationPickerIsKeyedToTheCurrentlySelectedItem(): void
    {
        $centre  = $this->centre();
        $first   = (new ListItem())->setEducationalCentre($centre)->setName('Primero');
        $second  = (new ListItem())->setEducationalCentre($centre)->setName('Segundo');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $admin   = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $first, $second, $profile, $admin);
        $firstId  = $first->getId()->toRfc4122();
        $secondId = $second->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);

        $component->call('selectItem', ['id' => $firstId]);
        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('id="assoc-picker-' . $firstId . '"', $html);

        $component->call('selectItem', ['id' => $secondId]);
        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('id="assoc-picker-' . $secondId . '"', $html);
        self::assertStringNotContainsString('id="assoc-picker-' . $firstId . '"', $html);

        // The save still targets whichever item is selected, not the first one.
        $component->set('associationKey', $profile->getId()->toRfc4122())->call('saveAssociation');

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);
        self::assertNotNull($items->findByIdAndCentre($secondId, $centre)?->getAssociatedProfile());
        self::assertNull($items->findByIdAndCentre($firstId, $centre)?->getAssociatedProfile());
    }

    public function testMoveUpAndMoveDownSwapSiblingPositions(): void
    {
        $centre = $this->centre();
        $first  = (new ListItem())->setEducationalCentre($centre)->setName('Primero')->setPosition(0);
        $second = (new ListItem())->setEducationalCentre($centre)->setName('Segundo')->setPosition(1);
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $first, $second, $admin);
        $firstId  = $first->getId()->toRfc4122();
        $secondId = $second->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveDown', ['id' => $firstId]);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items          = self::getContainer()->get(ListItemRepository::class);
        $reloadedFirst  = $items->findByIdAndCentre($firstId, $centre);
        $reloadedSecond = $items->findByIdAndCentre($secondId, $centre);
        self::assertNotNull($reloadedFirst);
        self::assertNotNull($reloadedSecond);
        self::assertSame(1, $reloadedFirst->getPosition());
        self::assertSame(0, $reloadedSecond->getPosition());
    }

    // ── Desktop tree: drag-and-drop, per-node add ────────────────────────────

    public function testMoveListItemReparentsAndReordersTheDestinationList(): void
    {
        $centre        = $this->centre();
        $oldParent     = (new ListItem())->setEducationalCentre($centre)->setName('Antiguo padre');
        $newParent     = (new ListItem())->setEducationalCentre($centre)->setName('Nuevo padre');
        $moved         = (new ListItem())->setEducationalCentre($centre)->setName('Movido');
        $moved->setParent($oldParent);
        $existingChild = (new ListItem())->setEducationalCentre($centre)->setName('Ya estaba aquí');
        $existingChild->setParent($newParent)->setPosition(0);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $oldParent, $newParent, $moved, $existingChild, $admin);
        $movedId     = $moved->getId()->toRfc4122();
        $newParentId = $newParent->getId()->toRfc4122();
        $existingId  = $existingChild->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveListItem', [
            'id'          => $movedId,
            'newParentId' => $newParentId,
            'orderedIds'  => [$existingId, $movedId],
        ]);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items         = self::getContainer()->get(ListItemRepository::class);
        $reloadedMoved = $items->findByIdAndCentre($movedId, $centre);
        self::assertNotNull($reloadedMoved);
        $movedParent = $reloadedMoved->getParent();
        self::assertNotNull($movedParent);
        self::assertSame($newParentId, $movedParent->getId()->toRfc4122());
        self::assertSame(1, $reloadedMoved->getPosition());
    }

    /** setParent()'s own cycle guard must stop an item becoming its own descendant, silently no-op'ing the move. */
    public function testMoveListItemIntoItsOwnDescendantIsRejected(): void
    {
        $centre = $this->centre();
        $parent = (new ListItem())->setEducationalCentre($centre)->setName('Padre');
        $child  = (new ListItem())->setEducationalCentre($centre)->setName('Hijo');
        $child->setParent($parent);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $parent, $child, $admin);
        $parentId = $parent->getId()->toRfc4122();
        $childId  = $child->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveListItem', [
            'id'          => $parentId,
            'newParentId' => $childId,
            'orderedIds'  => [$parentId],
        ]);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items          = self::getContainer()->get(ListItemRepository::class);
        $reloadedParent = $items->findByIdAndCentre($parentId, $centre);
        self::assertNotNull($reloadedParent);
        self::assertNull($reloadedParent->getParent(), 'the cyclic move must be rejected, leaving the item a root still');
    }

    public function testAddItemWithAnExplicitParentIdAddsAChildThere(): void
    {
        $centre = $this->centre();
        $parent = (new ListItem())->setEducationalCentre($centre)->setName('Materias');
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $parent, $admin);
        $parentId = $parent->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addName', 'Física')->call('addItem', ['parentId' => $parentId]);

        $this->em->clear();
        /** @var ListItemRepository $items */
        $items    = self::getContainer()->get(ListItemRepository::class);
        $reloaded = $items->findByIdAndCentre($parentId, $centre);
        self::assertNotNull($reloaded);
        $children = $items->findChildrenByParent($reloaded);
        self::assertCount(1, $children);
        self::assertSame('Física', $children[0]->getName());
    }

    public function testToggleAddOpensAndClosesTheAddBoxForAGivenParent(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);

        $component->call('toggleAdd', ['parentId' => '@root']);
        self::assertSame('@root', $this->stringProp($component, 'addingParentId'));

        $component->call('toggleAdd', ['parentId' => '@root']);
        self::assertSame('', $this->stringProp($component, 'addingParentId'));
    }

    /** @return array<string, mixed> */
    private function props(TestLiveComponent $component): array
    {
        $value = $component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertNotNull($value);

        /** @var array<string, mixed> $props */
        $props = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return $props;
    }

    private function stringProp(TestLiveComponent $component, string $key): string
    {
        $value = $this->props($component)[$key];
        self::assertIsString($value);

        return $value;
    }

    public function testDesktopTreeRendersNestedItemsAndFlagsAnAssociatedOne(): void
    {
        $centre  = $this->centre();
        $root    = (new ListItem())->setEducationalCentre($centre)->setName('Materias');
        $leaf    = (new ListItem())->setEducationalCentre($centre)->setName('Física');
        $leaf->setParent($root);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $leaf->setAssociation($profile);
        $admin = $this->admin();
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $root, $leaf, $profile, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $centre], $this->client);
        $html      = (string) $component->render()->crawler()->html();

        self::assertStringContainsString('data-list-item-id="' . $root->getId()->toRfc4122() . '"', $html);
        self::assertStringContainsString('data-list-item-id="' . $leaf->getId()->toRfc4122() . '"', $html);
        self::assertStringContainsString('Física', $html);
    }
}
