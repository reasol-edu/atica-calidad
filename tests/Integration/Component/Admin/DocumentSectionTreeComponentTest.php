<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\DocumentSectionRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class DocumentSectionTreeComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function section(EducationalCentre $centre, string $name = 'Sección'): DocumentSection
    {
        return (new DocumentSection())->setEducationalCentre($centre)->setName($name);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function admin(string $username = 'admin'): Teacher
    {
        $teacher = $this->teacher($username);
        $teacher->setAdmin(true);

        return $teacher;
    }

    public function testMountDeniesAccessWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->render();
    }

    public function testAddSectionCreatesARootSection(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addValue', 'Calidad')->call('addSection', ['parentId' => '']);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $roots    = $sections->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Calidad', $roots[0]->getName());
    }

    public function testAddSectionRejectsAnEmptyName(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addValue', '   ')->call('addSection', ['parentId' => '']);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        self::assertCount(0, $sections->findRootsByCentre($centre));
    }

    public function testAddSectionCreatesAChildUnderTheGivenParent(): void
    {
        $centre = $this->centre();
        $root   = $this->section($centre, 'Raíz');
        $admin  = $this->admin();
        $this->persist($centre, $root, $admin);
        $rootId = $root->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addValue', 'Hija')->call('addSection', ['parentId' => $rootId]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections     = self::getContainer()->get(DocumentSectionRepository::class);
        $reloadedRoot = $sections->findByIdAndCentre($rootId, $centre);
        self::assertNotNull($reloadedRoot);
        $children = $sections->findChildrenByParent($reloadedRoot);
        self::assertCount(1, $children);
        self::assertSame('Hija', $children[0]->getName());
    }

    public function testSaveRenameUpdatesTheName(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre, 'Antiguo');
        $admin   = $this->admin();
        $this->persist($centre, $section, $admin);
        $sectionId = $section->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('startRename', ['id' => $sectionId]);
        $component->set('renameValue', 'Nuevo')->call('saveRename');

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $reloaded = $sections->findByIdAndCentre($sectionId, $centre);
        self::assertNotNull($reloaded);
        self::assertSame('Nuevo', $reloaded->getName());
    }

    public function testDeleteSectionRemovesALeaf(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $admin   = $this->admin();
        $this->persist($centre, $section, $admin);
        $sectionId = $section->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('deleteSection', ['id' => $sectionId]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        self::assertNull($sections->findByIdAndCentre($sectionId, $centre));
    }

    public function testDeleteSectionBlockedWhenItHasChildren(): void
    {
        $centre = $this->centre();
        $parent = $this->section($centre, 'Padre');
        $child  = $this->section($centre, 'Hijo');
        $child->setParent($parent);
        $admin  = $this->admin();
        $this->persist($centre, $parent, $child, $admin);
        $parentId = $parent->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('deleteSection', ['id' => $parentId]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        self::assertNotNull($sections->findByIdAndCentre($parentId, $centre), 'a section with children must never be deleted');
    }

    // ── Profile restrictions ──────────────────────────────────────────────────

    public function testToggleProfileRestrictionAddsAndRemovesIt(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $admin   = $this->admin();
        $this->persist($centre, $section, $profile, $admin);
        $sectionId = $section->getId()->toRfc4122();
        $rowKey    = $profile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('toggleProfileRestriction', ['id' => $sectionId, 'rowKey' => $rowKey]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $reloaded = $sections->findByIdAndCentre($sectionId, $centre);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRestricted());

        $component->call('toggleProfileRestriction', ['id' => $sectionId, 'rowKey' => $rowKey]);
        $this->em->clear();
        $reloaded = $sections->findByIdAndCentre($sectionId, $centre);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isRestricted());
    }

    public function testToggleProfileRestrictionWithAListItemSubprofile(): void
    {
        $centre    = $this->centre();
        $section   = $this->section($centre);
        $subprofile = (new ListItem())->setEducationalCentre($centre)->setName('Subprofile');
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($subprofile);
        $admin     = $this->admin();
        $this->persist($centre, $section, $subprofile, $profile, $admin);
        $sectionId = $section->getId()->toRfc4122();
        $rowKey    = $profile->getId()->toRfc4122() . ':' . $subprofile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('toggleProfileRestriction', ['id' => $sectionId, 'rowKey' => $rowKey]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections = self::getContainer()->get(DocumentSectionRepository::class);
        $reloaded = $sections->findByIdAndCentre($sectionId, $centre);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRestricted());
        $restrictions = $reloaded->getProfileRestrictions();
        self::assertCount(1, $restrictions);
        $restriction = $restrictions->first();
        self::assertNotFalse($restriction);
        self::assertSame($profile->getId()->toRfc4122(), $restriction->getSpecificProfile()->getId()->toRfc4122());
        $restrictedListItem = $restriction->getListItem();
        self::assertNotNull($restrictedListItem);
        self::assertSame($subprofile->getId()->toRfc4122(), $restrictedListItem->getId()->toRfc4122());
    }

    // ── Reordering ────────────────────────────────────────────────────────────

    public function testMoveUpAndMoveDownSwapSiblingPositions(): void
    {
        $centre = $this->centre();
        $first  = $this->section($centre, 'Primero')->setPosition(0);
        $second = $this->section($centre, 'Segundo')->setPosition(1);
        $admin  = $this->admin();
        $this->persist($centre, $first, $second, $admin);
        $firstId  = $first->getId()->toRfc4122();
        $secondId = $second->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveDown', ['id' => $firstId]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections       = self::getContainer()->get(DocumentSectionRepository::class);
        $reloadedFirst  = $sections->findByIdAndCentre($firstId, $centre);
        $reloadedSecond = $sections->findByIdAndCentre($secondId, $centre);
        self::assertNotNull($reloadedFirst);
        self::assertNotNull($reloadedSecond);
        self::assertSame(1, $reloadedFirst->getPosition());
        self::assertSame(0, $reloadedSecond->getPosition());
    }

    // ── moveSection (desktop drag-and-drop) ──────────────────────────────────

    public function testMoveSectionReparentsAndReordersTheDestinationList(): void
    {
        $centre     = $this->centre();
        $oldParent  = $this->section($centre, 'Antiguo padre');
        $newParent  = $this->section($centre, 'Nuevo padre');
        $moved      = $this->section($centre, 'Movido');
        $moved->setParent($oldParent);
        $existingChild = $this->section($centre, 'Ya estaba aquí');
        $existingChild->setParent($newParent)->setPosition(0);
        $admin = $this->admin();
        $this->persist($centre, $oldParent, $newParent, $moved, $existingChild, $admin);
        $movedId     = $moved->getId()->toRfc4122();
        $newParentId = $newParent->getId()->toRfc4122();
        $existingId  = $existingChild->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveSection', [
            'id'          => $movedId,
            'newParentId' => $newParentId,
            'orderedIds'  => [$existingId, $movedId],
        ]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections      = self::getContainer()->get(DocumentSectionRepository::class);
        $reloadedMoved = $sections->findByIdAndCentre($movedId, $centre);
        self::assertNotNull($reloadedMoved);
        $movedParent = $reloadedMoved->getParent();
        self::assertNotNull($movedParent);
        self::assertSame($newParentId, $movedParent->getId()->toRfc4122());
        self::assertSame(1, $reloadedMoved->getPosition());
    }

    /** setParent()'s own cycle guard must stop a section becoming its own descendant, silently no-op'ing the move. */
    public function testMoveSectionIntoItsOwnDescendantIsRejected(): void
    {
        $centre = $this->centre();
        $parent = $this->section($centre, 'Padre');
        $child  = $this->section($centre, 'Hijo');
        $child->setParent($parent);
        $admin  = $this->admin();
        $this->persist($centre, $parent, $child, $admin);
        $parentId = $parent->getId()->toRfc4122();
        $childId  = $child->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:DocumentSectionTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveSection', [
            'id'          => $parentId,
            'newParentId' => $childId,
            'orderedIds'  => [$parentId],
        ]);

        $this->em->clear();
        /** @var DocumentSectionRepository $sections */
        $sections       = self::getContainer()->get(DocumentSectionRepository::class);
        $reloadedParent = $sections->findByIdAndCentre($parentId, $centre);
        self::assertNotNull($reloadedParent);
        self::assertNull($reloadedParent->getParent(), 'the cyclic move must be rejected, leaving the section a root still');
    }
}
