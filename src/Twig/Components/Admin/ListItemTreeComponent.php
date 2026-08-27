<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\Tag;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;
use App\Repository\TagRepository;
use App\Security\Voter\EducationalCentreVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Breadcrumb drill-down editor for a centre's custom lists: one level of
 * items visible at a time (root items, or the children of whichever item
 * the breadcrumb currently points at), unbounded depth. Tags are attached
 * directly to any item and inherited by its descendants (see ListItem).
 */
#[AsLiveComponent]
class ListItemTreeComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** '' means the root level. */
    #[LiveProp(writable: true)]
    public string $currentParentId = '';

    #[LiveProp(writable: true)]
    public string $selectedId = '';

    #[LiveProp(writable: true)]
    public string $addName = '';

    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public bool $editActive = true;

    #[LiveProp(writable: true)]
    public string $newTagName = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public bool $confirmingDelete = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ListItemRepository $items,
        private readonly TagRepository $tags,
        private readonly SpecificProfileRepository $profiles,
        private readonly SpecificProfileAssignmentRepository $assignments,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $this->centre = $centre;
    }

    // ── Navigation data ──────────────────────────────────────────────────────

    public function getCurrentParent(): ?ListItem
    {
        if ($this->currentParentId === '') {
            return null;
        }

        return $this->items->findByIdAndCentre($this->currentParentId, $this->centre);
    }

    /** @return ListItem[] */
    public function getVisibleItems(): array
    {
        $parent = $this->getCurrentParent();

        return $parent === null
            ? $this->items->findRootsByCentre($this->centre)
            : $this->items->findChildrenByParent($parent);
    }

    /**
     * Root-first path of ancestors down to (and including) the current parent.
     *
     * @return ListItem[]
     */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentParent(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    public function getSelected(): ?ListItem
    {
        if ($this->selectedId === '') {
            return null;
        }

        return $this->items->findByIdAndCentre($this->selectedId, $this->centre);
    }

    /** @return string[] */
    public function getExistingTagNames(): array
    {
        return array_map(static fn (Tag $t): string => $t->getName(), $this->tags->findByCentre($this->centre));
    }

    /**
     * Tags visible on the selected item but attached to one of its ancestors, not itself.
     *
     * @return Tag[]
     */
    public function getInheritedTags(): array
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return [];
        }

        $own = $selected->getTags();

        return array_values(array_filter(
            $selected->getEffectiveTags()->toArray(),
            static fn (Tag $tag): bool => !$own->contains($tag)
        ));
    }

    // ── Navigation actions ───────────────────────────────────────────────────

    #[LiveAction]
    public function selectItem(#[LiveArg] string $id): void
    {
        $this->selectedId = $id;
        $this->loadDetail();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedId = '';
        $this->errors     = [];
    }

    #[LiveAction]
    public function openLevel(#[LiveArg] string $id): void
    {
        $this->currentParentId = $id;
        $this->selectedId      = '';
        $this->errors          = [];
    }

    private function loadDetail(): void
    {
        $this->errors           = [];
        $this->confirmingDelete = false;
        $this->newTagName       = '';
        $selected                = $this->getSelected();
        $this->editName          = $selected?->getName() ?? '';
        $this->editActive        = $selected?->isActive() ?? true;
    }

    // ── Add / save / delete ──────────────────────────────────────────────────

    #[LiveAction]
    public function addItem(): void
    {
        $name = trim($this->addName);
        if ($name === '') {
            $this->errors = ['add' => $this->t('responsibilities.lists.error.name_required')];

            return;
        }

        $parent = $this->getCurrentParent();
        $item   = (new ListItem())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($parent === null ? $this->items->nextRootPosition($this->centre) : $this->items->nextChildPosition($parent));
        $item->setParent($parent);

        $this->em->persist($item);
        $this->em->flush();

        $this->addName = '';
        $this->selectItem($item->getId()->toRfc4122());
    }

    #[LiveAction]
    public function saveDetail(): void
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $name = trim($this->editName);
        if ($name === '') {
            $this->errors = ['name' => $this->t('responsibilities.lists.error.name_required')];

            return;
        }

        $selected->setName($name);
        $selected->setActive($this->editActive);
        $this->em->flush();

        $this->errors = [];
        $this->flashSuccess($this->t('responsibilities.lists.flash.saved'));
    }

    #[LiveAction]
    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    #[LiveAction]
    public function deleteSelected(): void
    {
        $this->confirmingDelete = false;
        $selected               = $this->getSelected();
        if ($selected === null) {
            return;
        }

        if (!$selected->isLeaf()) {
            $this->errors = ['delete' => $this->t('responsibilities.lists.error.delete_has_children')];

            return;
        }

        if ($this->profiles->isListItemInUse($selected) || $this->assignments->isListItemAssigned($selected)) {
            $this->errors = ['delete' => $this->t('responsibilities.lists.error.delete_in_use')];

            return;
        }

        $ownTags = $selected->getTags()->toArray();
        $parent  = $selected->getParent();

        $this->em->remove($selected);
        $this->em->flush();
        $this->pruneOrphanedTags($ownTags);

        $this->selectedId      = '';
        $this->currentParentId = $parent?->getId()->toRfc4122() ?? '';
        $this->loadDetail();
        $this->flashSuccess($this->t('responsibilities.lists.flash.deleted'));
    }

    // ── Reordering ───────────────────────────────────────────────────────────

    #[LiveAction]
    public function moveUp(#[LiveArg] string $id): void
    {
        $this->move($id, -1);
    }

    #[LiveAction]
    public function moveDown(#[LiveArg] string $id): void
    {
        $this->move($id, 1);
    }

    private function move(string $id, int $direction): void
    {
        $target = $this->items->findByIdAndCentre($id, $this->centre);
        if ($target === null) {
            return;
        }

        $parent   = $target->getParent();
        $siblings = array_values($parent === null
            ? $this->items->findRootsByCentre($this->centre)
            : $this->items->findChildrenByParent($parent));

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling === $target) {
                $index = $i;

                break;
            }
        }

        $swapWith = $index === null ? null : ($siblings[$index + $direction] ?? null);
        if ($swapWith === null) {
            return;
        }

        $targetPosition = $target->getPosition();
        $target->setPosition($swapWith->getPosition());
        $swapWith->setPosition($targetPosition);

        $this->em->flush();
    }

    #[LiveAction]
    public function sortAlphabetically(): void
    {
        $items = $this->getVisibleItems();
        usort($items, static fn (ListItem $a, ListItem $b) => strcmp($a->getName(), $b->getName()));

        foreach ($items as $position => $item) {
            $item->setPosition($position);
        }

        $this->em->flush();
    }

    // ── Tags ─────────────────────────────────────────────────────────────────

    #[LiveAction]
    public function addTag(): void
    {
        $selected = $this->getSelected();
        $name     = trim($this->newTagName);
        if ($selected === null || $name === '') {
            return;
        }

        $tag = $this->tags->findOneByNameInsensitive($this->centre, $name);
        if ($tag === null) {
            $tag = (new Tag())->setEducationalCentre($this->centre)->setName($name);
            $this->em->persist($tag);
        }

        $selected->addTag($tag);
        $this->em->flush();

        $this->newTagName = '';
    }

    #[LiveAction]
    public function removeTag(#[LiveArg] string $tagId): void
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $tag = $selected->getTags()->findFirst(
            static fn (int $i, Tag $t): bool => $t->getId()->toRfc4122() === $tagId
        );
        if ($tag === null) {
            return;
        }

        $selected->removeTag($tag);
        $this->em->flush();
        $this->pruneOrphanedTags([$tag]);
    }

    /** @param Tag[] $candidates */
    private function pruneOrphanedTags(array $candidates): void
    {
        $pruned = false;
        foreach ($candidates as $tag) {
            if ($this->tags->isOrphan($tag)) {
                $this->em->remove($tag);
                $pruned = true;
            }
        }
        if ($pruned) {
            $this->em->flush();
        }
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    /**
     * LiveAction responses only re-render this component's fragment, not the
     * layout, so a plain addFlash() never reaches the page until the next
     * full navigation. Dispatch a browser event instead so the layout's JS
     * can render the flash immediately.
     */
    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }
}
