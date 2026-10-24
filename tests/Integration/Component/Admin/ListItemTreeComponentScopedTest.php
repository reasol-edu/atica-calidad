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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** The editor scoped to one list (root item): what the per-list screen mounts. */
final class ListItemTreeComponentScopedTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private EducationalCentre $centre;

    private ListItem $root;

    private ListItem $child;

    private ListItem $otherRoot;

    private ListItem $otherChild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->root   = (new ListItem())->setEducationalCentre($this->centre)->setName('Departamento')->setPosition(0);
        $this->child  = (new ListItem())->setEducationalCentre($this->centre)->setName('Matemáticas');
        $this->child->setParent($this->root);
        $this->otherRoot  = (new ListItem())->setEducationalCentre($this->centre)->setName('Etapa')->setPosition(1);
        $this->otherChild = (new ListItem())->setEducationalCentre($this->centre)->setName('Primaria');
        $this->otherChild->setParent($this->otherRoot);

        $admin = (new Teacher(new PersonName('Nombre', 'director')))->setUsername('director');
        $this->centre->getAdmins()->add($admin);
        $this->persist($this->centre, $this->root, $this->child, $this->otherRoot, $this->otherChild, $admin);
        $this->loginAs($admin, $this->centre);
    }

    private function id(ListItem $item): string
    {
        return $item->getId()->toRfc4122();
    }

    private function repository(): ListItemRepository
    {
        $this->em->clear();

        /** @var ListItemRepository $items */
        $items = self::getContainer()->get(ListItemRepository::class);

        return $items;
    }

    public function testOnlyTheRootsSubtreeIsRendered(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $html      = (string) $component->render();

        self::assertStringContainsString('Matemáticas', $html);
        self::assertStringNotContainsString('Primaria', $html);
        self::assertStringNotContainsString('Etapa', $html);
    }

    public function testMountRejectsAnItemThatIsNotARoot(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->child], $this->client)->render();
    }

    public function testAddingAtTheTopLevelCreatesAChildOfTheRootNotANewList(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->set('addName', 'Lengua')->call('addItem');

        $items = $this->repository();
        self::assertCount(2, $items->findRootsByCentre($this->centre));
        $children = array_map(static fn (ListItem $i): string => $i->getName(), $items->findChildrenByParent($items->findByIdAndCentre($this->id($this->root), $this->centre) ?? throw new \LogicException()));
        self::assertContains('Lengua', $children);
    }

    public function testDroppingOnTheTopLevelListKeepsTheItemUnderTheRoot(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('moveListItem', ['id' => $this->id($this->child), 'newParentId' => '', 'orderedIds' => [$this->id($this->child)]]);

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertNotNull($reloaded);
        self::assertSame($this->id($this->root), $reloaded->getParent()?->getId()->toRfc4122());
    }

    public function testBulkMoveCarriesABranchToAnotherList(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->child)])->set('bulkMoveTargetId', $this->id($this->otherRoot))->call('bulkMoveSelected');

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertNotNull($reloaded);
        self::assertSame($this->id($this->otherRoot), $reloaded->getParent()?->getId()->toRfc4122());
    }

    public function testBulkMoveAsksForConfirmationWhenTheDestinationChangesTheInheritedProfiles(): void
    {
        $profile = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura');
        $this->persist($profile);
        $this->otherRoot->addProfileAssociation($profile, null);
        $this->flush();

        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->child)])->set('bulkMoveTargetId', $this->id($this->otherRoot))->call('bulkMoveSelected');

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertSame($this->id($this->root), $reloaded?->getParent()?->getId()->toRfc4122(), 'nothing moves until it is confirmed');
        self::assertStringContainsString('cambian los perfiles', (string) $component->render());

        $component->call('confirmBulkMove');

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertSame($this->id($this->otherRoot), $reloaded?->getParent()?->getId()->toRfc4122());
    }

    public function testConfirmingDoesNothingIfTheDestinationChangedAfterTheWarning(): void
    {
        $profile = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura');
        $this->persist($profile);
        $this->otherRoot->addProfileAssociation($profile, null);
        $this->flush();

        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->child)])->set('bulkMoveTargetId', $this->id($this->otherRoot))->call('bulkMoveSelected');
        $component->set('bulkMoveTargetId', $this->id($this->otherChild))->call('confirmBulkMove');

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertSame($this->id($this->root), $reloaded?->getParent()?->getId()->toRfc4122(), 'the confirmation only covers the destination that was warned about');
    }

    public function testBulkMoveDoesNotAskWhenNoProfileIsInvolved(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->child)])->set('bulkMoveTargetId', $this->id($this->otherRoot))->call('bulkMoveSelected');

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertSame($this->id($this->otherRoot), $reloaded?->getParent()?->getId()->toRfc4122());
    }

    public function testBulkMoveToTheRootLevelMakesAnIndependentList(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('toggleBulkSelectMode');
        $component->set('bulkSelectedIds', [$this->id($this->child)])->call('bulkMoveSelected');

        $reloaded = $this->repository()->findByIdAndCentre($this->id($this->child), $this->centre);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRoot());
    }

    public function testBulkMoveOptionsOfferTheOtherListsToo(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('toggleBulkSelectMode');
        $html = (string) $component->set('bulkSelectedIds', [$this->id($this->child)])->render();

        self::assertStringContainsString('value="' . $this->id($this->otherRoot) . '"', $html);
        self::assertStringContainsString('value="' . $this->id($this->otherChild) . '"', $html);
    }

    public function testDeletingTheManagedListGoesBackToTheLists(): void
    {
        $component = $this->createLiveComponent('Admin:ListItemTreeComponent', ['centre' => $this->centre, 'root' => $this->root], $this->client);
        $component->call('selectItem', ['id' => $this->id($this->root)]);
        $component->call('deleteSelected');

        self::assertTrue($component->response()->isRedirect('/centro/' . $this->centre->getId()->toRfc4122() . '/responsabilidades/listas'));
        self::assertNull($this->repository()->findByIdAndCentre($this->id($this->root), $this->centre));
    }
}
