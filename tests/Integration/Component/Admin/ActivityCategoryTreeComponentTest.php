<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\ActivityCategoryRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class ActivityCategoryTreeComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre, string $name = 'Categoría'): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName($name);
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

    public function testMountDeniesAccessWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->render();
    }

    public function testAddCategoryCreatesARootCategory(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addValue', 'Programaciones didácticas')->call('addCategory');

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        $roots      = $categories->findRootsByCentre($centre);
        self::assertCount(1, $roots);
        self::assertSame('Programaciones didácticas', $roots[0]->getName());
    }

    public function testAddCategoryRejectsAnEmptyName(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addValue', '   ')->call('addCategory');

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        self::assertCount(0, $categories->findRootsByCentre($centre));
    }

    public function testAddCategoryCreatesAChildUnderTheCurrentParent(): void
    {
        $centre = $this->centre();
        $root   = $this->category($centre, 'Raíz');
        $admin  = $this->admin();
        $this->persist($centre, $root, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', [
            'centre' => $centre,
        ], $this->client);
        $component->call('openLevel', ['id' => $root->getId()->toRfc4122()]);
        $component->set('addValue', 'Hija')->call('addCategory');

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories     = self::getContainer()->get(ActivityCategoryRepository::class);
        $reloadedRoot   = $categories->findByIdAndCentre($root->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloadedRoot);
        $children = $categories->findChildrenByParent($reloadedRoot);
        self::assertCount(1, $children);
        self::assertSame('Hija', $children[0]->getName());
    }

    public function testSaveRenameUpdatesTheName(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Antiguo nombre');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);
        $categoryId = $category->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('startRename', ['id' => $categoryId]);
        self::assertSame('Antiguo nombre', $this->stringProp($component, 'renameValue'));

        $component->set('renameValue', 'Nuevo nombre')->call('saveRename');

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        $reloaded   = $categories->findByIdAndCentre($categoryId, $centre);
        self::assertNotNull($reloaded);
        self::assertSame('Nuevo nombre', $reloaded->getName());
    }

    public function testSaveRenameRejectsAnEmptyName(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Nombre original');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);
        $categoryId = $category->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('startRename', ['id' => $categoryId]);
        $component->set('renameValue', '   ')->call('saveRename');

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        $reloaded   = $categories->findByIdAndCentre($categoryId, $centre);
        self::assertNotNull($reloaded);
        self::assertSame('Nombre original', $reloaded->getName());
    }

    public function testDeleteCategoryRemovesALeafWithNoActivities(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);
        $categoryId = $category->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('deleteCategory', ['id' => $categoryId]);

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        self::assertNull($categories->findByIdAndCentre($categoryId, $centre));
    }

    public function testDeleteCategoryBlockedWhenItHasChildren(): void
    {
        $centre = $this->centre();
        $parent = $this->category($centre, 'Padre');
        $child  = $this->category($centre, 'Hijo');
        $child->setParent($parent);
        $admin  = $this->admin();
        $this->persist($centre, $parent, $child, $admin);
        $parentId = $parent->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('deleteCategory', ['id' => $parentId]);

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        self::assertNotNull($categories->findByIdAndCentre($parentId, $centre), 'a category with children must never be deleted');
    }

    public function testDeleteCategoryBlockedWhenItHasActivities(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 6);
        $admin    = $this->admin();
        $this->persist($centre, $category, $activity, $admin);
        $categoryId = $category->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('deleteCategory', ['id' => $categoryId]);

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);
        self::assertNotNull($categories->findByIdAndCentre($categoryId, $centre), 'a category with activities must never be deleted');
    }

    public function testDeleteCategoryNavigatesUpWhenDeletingTheCurrentlyOpenCategory(): void
    {
        $centre = $this->centre();
        $parent = $this->category($centre, 'Padre');
        $child  = $this->category($centre, 'Hijo');
        $child->setParent($parent);
        $admin  = $this->admin();
        $this->persist($centre, $parent, $child, $admin);
        $parentId = $parent->getId()->toRfc4122();
        $childId  = $child->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('openLevel', ['id' => $childId]);
        $component->call('deleteCategory', ['id' => $childId]);

        self::assertSame($parentId, $this->stringProp($component, 'currentParentId'));
    }

    public function testMoveUpAndMoveDownSwapSiblingPositions(): void
    {
        $centre = $this->centre();
        $first  = $this->category($centre, 'Primero')->setPosition(0);
        $second = $this->category($centre, 'Segundo')->setPosition(1);
        $admin  = $this->admin();
        $this->persist($centre, $first, $second, $admin);
        $firstId  = $first->getId()->toRfc4122();
        $secondId = $second->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveDown', ['id' => $firstId]);

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories    = self::getContainer()->get(ActivityCategoryRepository::class);
        $reloadedFirst  = $categories->findByIdAndCentre($firstId, $centre);
        $reloadedSecond = $categories->findByIdAndCentre($secondId, $centre);
        self::assertNotNull($reloadedFirst);
        self::assertNotNull($reloadedSecond);
        self::assertSame(1, $reloadedFirst->getPosition());
        self::assertSame(0, $reloadedSecond->getPosition());

        $component->call('moveUp', ['id' => $firstId]);
        $this->em->clear();
        $reloadedFirst  = $categories->findByIdAndCentre($firstId, $centre);
        $reloadedSecond = $categories->findByIdAndCentre($secondId, $centre);
        self::assertNotNull($reloadedFirst);
        self::assertNotNull($reloadedSecond);
        self::assertSame(0, $reloadedFirst->getPosition());
        self::assertSame(1, $reloadedSecond->getPosition());
    }

    public function testMoveUpOnTheFirstSiblingIsANoOp(): void
    {
        $centre = $this->centre();
        $first  = $this->category($centre, 'Primero')->setPosition(0);
        $second = $this->category($centre, 'Segundo')->setPosition(1);
        $admin  = $this->admin();
        $this->persist($centre, $first, $second, $admin);
        $firstId = $first->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:ActivityCategoryTreeComponent', ['centre' => $centre], $this->client);
        $component->call('moveUp', ['id' => $firstId]);

        $this->em->clear();
        /** @var ActivityCategoryRepository $categories */
        $categories     = self::getContainer()->get(ActivityCategoryRepository::class);
        $reloadedFirst  = $categories->findByIdAndCentre($firstId, $centre);
        self::assertNotNull($reloadedFirst);
        self::assertSame(0, $reloadedFirst->getPosition());
    }
}
