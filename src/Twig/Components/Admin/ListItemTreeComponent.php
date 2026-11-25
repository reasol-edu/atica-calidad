<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\ListItemProfile;
use App\Model\ProfileAssignmentRow;
use App\Entity\Teacher;
use App\Repository\ListItemRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\LeafTeachers;
use App\Service\ProfileAssignmentRowBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Editor for a centre's custom lists: a full nested drag-and-drop tree for desktop, and a
 * breadcrumb drill-down fallback (one level at a time, up/down reordering instead of dragging)
 * for small screens — same state and LiveActions, two renderings picked by CSS breakpoint (see
 * the component's template), mirroring DocumentSectionTreeComponent's own split. Selecting an
 * item (from either view) opens the single detail panel below, shared by both views, where
 * renaming, activation and its profile associations are edited — dragging only ever
 * reorders/reparents, it never opens that panel.
 */
#[AsLiveComponent]
class ListItemTreeComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    /**
     * Marks the desktop tree's root "add" box as open in $addingParentId — distinct from '' (no
     * box open) and from a real item id. Never passed as the actual parent id to addItem(): a
     * root item is created with parentId === ''.
     */
    private const ROOT_SENTINEL = '@root';

    #[LiveProp]
    public EducationalCentre $centre;

    /**
     * The list (root item) being managed: the tree, the mobile drill-down and every "add at the top"
     * action are scoped to its descendants, and '' as a parent id then means "this root" rather than
     * "the centre's root level". Null only for the centre-wide view (every root at once), which the
     * pages no longer use but the component still supports.
     */
    #[LiveProp]
    public ?ListItem $root = null;

    /** '' means the root level (the scoped root's own children when $root is set). Drives the mobile breadcrumb drill-down only. */
    #[LiveProp(writable: true)]
    public string $currentParentId = '';

    #[LiveProp(writable: true)]
    public string $selectedId = '';

    #[LiveProp(writable: true)]
    public string $addName = '';

    /** '' = no add box open, self::ROOT_SENTINEL = the desktop tree's root add box, otherwise an item id. Desktop tree only — the mobile view's add box is always open, tied to currentParentId instead. */
    #[LiveProp(writable: true)]
    public string $addingParentId = '';

    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public bool $editActive = true;

    /**
     * Keys (see ProfileAssignmentRow::key()) of the profiles/subprofiles this item is associated
     * with — its own, not the ones it inherits from an ancestor (empty when it has none).
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $associationKeys = [];

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public bool $confirmingDelete = false;

    /**
     * Whether checkboxes are showing instead of the normal select/rename controls, to assign one
     * profile/subprofile to several items in a single action instead of one at a time. Deliberately
     * NOT scoped to "the current level"/"the current branch" — a checked item stays checked while
     * navigating (either view), so a selection can span siblings across different parents too.
     */
    #[LiveProp(writable: true)]
    public bool $bulkSelectMode = false;

    /**
     * Ids of the items currently checked in bulk-select mode. Deliberately NOT `norender`: the
     * "N seleccionados" count and the action bar's visibility need to update as items are (un)checked.
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $bulkSelectedIds = [];

    /**
     * Same key format as $associationKeys, but for the bulk action bar's own picker — kept separate
     * so the two never interfere with each other.
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $bulkAssociationKeys = [];

    /**
     * Ids of the teachers associated with the selected item as its own (not the ones it inherits): what the «Docentes» picker of the
     * panel holds.
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $teacherIds = [];

    /**
     * The bulk action bar's own teacher picker, and what it does with them on the checked items: 'add', 'remove' or 'replace'.
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $bulkTeacherIds = [];

    #[LiveProp(writable: true)]
    public string $bulkTeacherMode = 'add';

    /**
     * Bumped after each bulk teacher action: the picker lives in a data-live-ignore wrapper keyed by it, so a new key makes the
     * page swap in a fresh, empty picker instead of keeping the teachers chosen for the previous action.
     */
    #[LiveProp]
    public int $bulkTeacherRound = 0;

    /** The tree shows only the leaves with no teacher in the course (and the branches leading to them), to find what is missing. */
    #[LiveProp(writable: true)]
    public bool $onlyWithoutTeacher = false;

    /** Asking for confirmation before removing the teachers that are not in the active course from every item of the tree. */
    #[LiveProp(writable: true)]
    public bool $confirmingRemoveOffYearTeachers = false;

    #[LiveProp(writable: true)]
    public bool $confirmingBulkDelete = false;

    /** '' = root level (no parent). Destination picker for bulk moving — the bulk action bar's own tree dropdown. */
    #[LiveProp(writable: true)]
    public string $bulkMoveTargetId = '';

    /** Set by a bulk move that would change the profiles applying to the moved items (see getBulkMoveKey()): the move waits for a confirmation. '' = nothing pending. */
    #[LiveProp(writable: true)]
    public string $bulkMoveConfirmKey = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ListItemRepository $items,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly \App\Repository\FormValueRepository $formValues,
        private readonly \App\Service\ListItemUsage $usage,
        private readonly TeacherRepository $teachers,
        private readonly LeafTeachers $leafTeachers,
    ) {}

    /**
     * Every item of the centre by id, in ONE fresh query (not the repository's per-request tree, which an action that has just removed
     * or moved items leaves stale): what the loops over many checked ids use instead of a query per id.
     *
     * @return array<string, ListItem>
     */
    private function itemIndex(): array
    {
        $index = [];
        foreach ($this->items->findAllByCentre($this->centre) as $item) {
            $index[$item->getId()->toRfc4122()] = $item;
        }

        return $index;
    }

    /** @var array<string, true>|null the items a profile takes its list from, read once per request when first needed */
    private ?array $profileListIds = null;

    /**
     * How many delivered documents (of the models filled in online) have an answer that is one of the items about to be deleted —
     * what the confirmation tells before the branch goes. Zero (and no query) when nothing is being deleted.
     */
    public function getFormUsage(): int
    {
        if ($this->confirmingDelete) {
            $selected = $this->getSelected();
            $roots    = $selected === null ? [] : [$selected];
        } elseif ($this->confirmingBulkDelete) {
            $roots = $this->topLevelSelectedItems();
        } else {
            return 0;
        }

        return $this->formUsageOf($roots);
    }

    /**
     * What a pending move would change: whether the profiles that apply to the moved items change, and in how many delivered documents of
     * the models filled in online they appear (a moved item stops being offered by the fields that take their options from the list it
     * leaves, and starts being offered by those of the list it joins).
     *
     * @return array{profiles: bool, forms: int}
     */
    public function getMoveImpact(): array
    {
        $roots  = array_values($this->topLevelSelectedItems());
        $target = $this->bulkMoveTargetId === '' ? null : $this->items->findByIdAndCentre($this->bulkMoveTargetId, $this->centre);

        return ['profiles' => $roots !== [] && $this->moveChangesProfiles($roots, $target), 'forms' => $this->formUsageOf($roots)];
    }

    /** @param iterable<ListItem> $roots branches */
    private function formUsageOf(iterable $roots): int
    {
        $ids = [];
        foreach ($roots as $root) {
            foreach ($this->items->findSubtree($root) as $node) {
                $ids[$node->getId()->toRfc4122()] = true;
            }
        }

        return $ids === [] ? 0 : $this->formValues->countDocumentsUsingItems(array_keys($ids));
    }

    public function mount(EducationalCentre $centre, ?ListItem $root = null): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $this->centre = $centre;

        if ($root !== null) {
            if (!$root->isRoot() || $root->getEducationalCentre() !== $centre) {
                throw new NotFoundHttpException();
            }
            $this->root            = $root;
            $this->currentParentId = $root->getId()->toRfc4122();
        }
    }

    public function getRootSentinel(): string
    {
        return self::ROOT_SENTINEL;
    }

    /** Id of the item the top level of the tree hangs from: the scoped root, or '' for the centre-wide view. */
    public function getScopeParentId(): string
    {
        return $this->root?->getId()->toRfc4122() ?? '';
    }

    /** Maps the '' the views send for "the top level" onto the scoped root, when there is one. */
    private function resolveParentId(string $parentId): string
    {
        return $parentId === '' ? $this->getScopeParentId() : $parentId;
    }

    // ── Desktop: full nested tree ────────────────────────────────────────────

    /**
     * The whole centre's list-item tree, nested, for the desktop drag-and-drop editor.
     *
     * @return array<int, array{item: ListItem, children: array<mixed>}>
     */
    public function getTree(): array
    {
        $byParent = [];
        foreach ($this->items->findAllByCentre($this->centre) as $item) {
            $key              = $item->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $item;
        }

        $nodes = $this->buildNodes($this->getScopeParentId(), $byParent);

        return $this->onlyWithoutTeacher ? $this->pruneToLeavesWithoutTeacher($nodes) : $nodes;
    }

    /**
     * Only the leaves nobody in the course teaches, with the branches that lead to them.
     *
     * @param array<int, array{item: ListItem, children: array<mixed>}> $nodes
     *
     * @return array<int, array{item: ListItem, children: array<mixed>}>
     */
    private function pruneToLeavesWithoutTeacher(array $nodes): array
    {
        $kept = [];
        foreach ($nodes as $node) {
            if ($node['children'] === []) {
                if ($this->lacksTeacherInCourse($node['item'])) {
                    $kept[] = $node;
                }

                continue;
            }
            /** @var array<int, array{item: ListItem, children: array<mixed>}> $children */
            $children = $node['children'];
            $children = $this->pruneToLeavesWithoutTeacher($children);
            if ($children !== []) {
                $kept[] = ['item' => $node['item'], 'children' => $children];
            }
        }

        return $kept;
    }

    /** Whether no teacher of the item (its own or inherited) is in the active course. */
    private function lacksTeacherInCourse(ListItem $item): bool
    {
        foreach ($item->getEffectiveTeachers() as $teacher) {
            if ($this->leafTeachers->isInCourse($teacher, $this->centre)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, ListItem[]> $byParent
     *
     * @return array<int, array{item: ListItem, children: array<mixed>}>
     */
    private function buildNodes(string $parentKey, array $byParent): array
    {
        $nodes = [];
        foreach ($byParent[$parentKey] ?? [] as $item) {
            $nodes[] = [
                'item'     => $item,
                'children' => $this->buildNodes($item->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }

    // ── Mobile: breadcrumb drill-down ────────────────────────────────────────

    /** The level being browsed; null at the centre's root level (never the case once a list is scoped, where the level is the root itself or below it). */
    public function getCurrentParent(): ?ListItem
    {
        $id = $this->resolveParentId($this->currentParentId);
        if ($id === '') {
            return null;
        }

        return $this->items->findByIdAndCentre($id, $this->centre);
    }

    /** @return ListItem[] every item of the level being browsed, whatever the filters */
    private function levelItems(): array
    {
        $parent = $this->getCurrentParent();

        return $parent === null
            ? $this->items->findRootsByCentre($this->centre)
            : $this->items->findChildrenByParent($parent);
    }

    /** @return ListItem[] */
    public function getVisibleItems(): array
    {
        $visible = $this->levelItems();

        if (!$this->onlyWithoutTeacher) {
            return $visible;
        }

        return array_values(array_filter($visible, function (ListItem $item): bool {
            foreach ($this->items->findLeafDescendants($item) as $leaf) {
                if ($this->lacksTeacherInCourse($leaf)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Path of ancestors down to (and including) the current parent, starting below the scoped root
     * — the root itself is the breadcrumb's "home", not a crumb.
     *
     * @return ListItem[]
     */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentParent(); $item !== null && !$this->isScopedRoot($item); $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    private function isScopedRoot(ListItem $item): bool
    {
        return $this->root !== null && $item->getId()->equals($this->root->getId());
    }

    public function getSelected(): ?ListItem
    {
        if ($this->selectedId === '') {
            return null;
        }

        return $this->items->findByIdAndCentre($this->selectedId, $this->centre);
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
        $this->currentParentId = $this->resolveParentId($id);
        $this->selectedId      = '';
        $this->errors          = [];
    }

    private function loadDetail(): void
    {
        $this->errors           = [];
        $this->confirmingDelete = false;
        $selected                = $this->getSelected();
        $this->editName          = $selected?->getName() ?? '';
        $this->editActive        = $selected?->isActive() ?? true;
        $this->associationKeys   = $this->associationKeysFor($selected);
        $this->teacherIds        = array_map(static fn (Teacher $t): string => $t->getId()->toRfc4122(), $selected?->getTeachers() ?? []);
    }

    /** @return string[] */
    private function associationKeysFor(?ListItem $item): array
    {
        if ($item === null) {
            return [];
        }

        return array_values(array_map(
            static fn (ListItemProfile $a): string => ProfileAssignmentRow::keyFor($a->getSpecificProfile(), $a->getSubprofile()),
            $item->getProfileAssociations()->toArray(),
        ));
    }

    // ── Add / save / delete ──────────────────────────────────────────────────

    /** Desktop tree only: toggles the inline add-box under $parentId (or the root box, for self::ROOT_SENTINEL). */
    #[LiveAction]
    public function toggleAdd(#[LiveArg] string $parentId): void
    {
        $this->addingParentId = $this->addingParentId === $parentId ? '' : $parentId;
        $this->addName        = '';
        $this->errors         = [];
    }

    /**
     * $parentId is '' for a root item — passed explicitly by both views: the mobile add box
     * always targets $currentParentId (whichever level is being browsed), the desktop tree's
     * per-node add box targets that node.
     */
    #[LiveAction]
    public function addItem(#[LiveArg] string $parentId = ''): void
    {
        $name = trim($this->addName);
        if ($name === '') {
            $this->errors = ['add' => $this->t('responsibilities.lists.error.name_required')];

            return;
        }

        $parentId = $this->resolveParentId($parentId);
        $parent   = $parentId === '' ? null : $this->items->findByIdAndCentre($parentId, $this->centre);
        if ($parentId !== '' && $parent === null) {
            return;
        }

        $item = (new ListItem())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($parent === null ? $this->items->nextRootPosition($this->centre) : $this->items->nextChildPosition($parent));
        $item->setParent($parent);

        $this->em->persist($item);
        $this->em->flush();

        $this->addName        = '';
        $this->addingParentId = '';
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

    /**
     * Deletes the selected item, along with its whole subtree if it has children — a list item is
     * just a node in a tree the centre defines for itself, so "delete this branch" is a normal,
     * expected operation, not something that should force clearing out every child by hand first.
     * Blocked only if the item itself or ANY descendant is still in use (a profile's list source, or
     * a teacher's assignment to it) — deleting through that would silently orphan it.
     */
    #[LiveAction]
    public function deleteSelected(): ?Response
    {
        $this->confirmingDelete = false;
        $selected               = $this->getSelected();
        if ($selected === null) {
            return null;
        }

        $subtree = $this->items->findSubtree($selected);

        $blocking = $this->usage->blocking($this->centre);
        foreach ($subtree as $node) {
            if (isset($blocking[$node->getId()->toRfc4122()])) {
                $this->errors = ['delete' => $this->t('responsibilities.lists.error.delete_in_use')];

                return null;
            }
        }

        // Deleting the managed list itself leaves this screen with nothing to show: back to the lists.
        $wasScopedRoot = $this->isScopedRoot($selected);

        $parent = $selected->getParent();

        // Remove deepest descendants first: findSubtree() walks pre-order (parent before its
        // children), so reversing it guarantees every node is removed only after all of its own
        // descendants already are.
        foreach (array_reverse($subtree) as $node) {
            $this->em->remove($node);
        }
        $this->em->flush();

        if ($wasScopedRoot) {
            $this->addFlash('success', $this->t('responsibilities.lists.flash.deleted'));

            return $this->redirectToRoute('app_responsibilities_lists', ['centreId' => $this->centre->getId()]);
        }

        $this->selectedId      = '';
        $this->currentParentId = $parent?->getId()->toRfc4122() ?? '';
        $this->loadDetail();
        $this->flashSuccess($this->t('responsibilities.lists.flash.deleted'));

        return null;
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

    /**
     * Desktop drag-and-drop: called once per drop with the id of the dragged item, the id of the
     * list it was dropped into ('' for the root list) and the full ordered list of item ids now
     * in that destination list (including the dragged one), read straight from the DOM by the
     * SortableJS wrapper. Handles same-level reordering and reparenting in one call — mirrors
     * DocumentSectionTreeComponent::moveSection().
     *
     * @param string[] $orderedIds
     */
    #[LiveAction]
    public function moveListItem(#[LiveArg] string $id, #[LiveArg] string $newParentId, #[LiveArg] array $orderedIds): void
    {
        $target = $this->items->findByIdAndCentre($id, $this->centre);
        // With «Sin docente» on, the siblings sent are only the visible ones: numbering them would scramble the hidden ones.
        if ($target === null || $this->onlyWithoutTeacher) {
            return;
        }

        $newParentId = $this->resolveParentId($newParentId);
        $newParent   = $newParentId === '' ? null : $this->items->findByIdAndCentre($newParentId, $this->centre);
        if ($newParentId !== '' && $newParent === null) {
            return;
        }

        $oldParent   = $target->getParent();
        $oldParentId = $oldParent?->getId()->toRfc4122() ?? '';

        try {
            $target->setParent($newParent);
        } catch (\LogicException) {
            return;
        }

        // The centre's tree is read once for all the siblings (not one query per id).
        $index = $this->itemIndex();
        foreach ($orderedIds as $position => $siblingId) {
            $sibling = $siblingId === $id ? $target : ($index[(string) $siblingId] ?? null);
            $sibling?->setPosition($position);
        }

        if ($oldParentId !== $newParentId) {
            $remaining = $oldParent === null
                ? $this->items->findRootsByCentre($this->centre)
                : $this->items->findChildrenByParent($oldParent);

            $position = 0;
            foreach ($remaining as $sibling) {
                if ($sibling === $target) {
                    continue;
                }
                $sibling->setPosition($position);
                ++$position;
            }
        }

        $this->em->flush();
    }

    #[LiveAction]
    public function sortAlphabetically(): void
    {
        $items = $this->levelItems();
        usort($items, static fn (ListItem $a, ListItem $b) => strcmp($a->getName(), $b->getName()));

        foreach ($items as $position => $item) {
            $item->setPosition($position);
        }

        $this->em->flush();
    }

    // ── Association with a profile/subprofile ─────────────────────────────────

    /** @return ProfileAssignmentRow[] every active plain profile, plus one row per active subprofile and per intermediate node of a profile's list, to pick an association from. */
    public function getAssociationRows(): array
    {
        return $this->rowBuilder->buildActiveRowsForAssociation($this->centre);
    }

    /**
     * The association rows $keys name, in the order given — stale or tampered keys (no such row any
     * more) are dropped.
     *
     * @param  string[]                $keys
     * @return ProfileAssignmentRow[]
     */
    private function associationRowsFor(array $keys): array
    {
        $rowsByKey = [];
        foreach ($this->getAssociationRows() as $row) {
            $rowsByKey[$row->key()] = $row;
        }

        $rows = [];
        foreach (array_unique(array_filter($keys, 'is_string')) as $key) {
            if (isset($rowsByKey[$key])) {
                $rows[] = $rowsByKey[$key];
            }
        }

        return $rows;
    }

    #[LiveAction]
    public function saveAssociation(): void
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $rows = $this->associationRowsFor($this->associationKeys);
        if ($rows === []) {
            $selected->clearProfileAssociations();
            $this->em->flush();
            $this->flashSuccess($this->t('responsibilities.lists.association.flash.cleared'));

            return;
        }

        // The item ends up with exactly the picked ones.
        $selected->clearProfileAssociations();
        foreach ($rows as $row) {
            $selected->addProfileAssociation($row->profile, $row->listItem);
        }
        $this->em->flush();
        $this->flashSuccess($this->t('responsibilities.lists.association.flash.saved'));
    }

    // ── Associated teachers ──────────────────────────────────────────────────

    /** @var list<Teacher>|null the teachers of the active course by name, read once per request when first needed */
    private ?array $courseTeachers = null;

    /**
     * @return list<Teacher> who can be associated: the active teachers of the centre's active course, by name — the same rule that
     *                       makes an association count (LeafTeachers::isInCourse()), so nobody can be picked who would count for nothing
     */
    public function getTeacherOptions(): array
    {
        $year = $this->centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->courseTeachers ??= array_values(array_filter(
            $this->teachers->findByAcademicYearOrderedByName($year),
            static fn (Teacher $teacher): bool => $teacher->isActive(),
        ));
    }

    /**
     * The options of the panel's picker for $item: the course's teachers plus those it already has as its own that are no longer in the
     * course — so saving does not silently drop them (they show in red; «Quitar docentes fuera de curso» is how they go).
     *
     * @return list<Teacher>
     */
    public function getTeacherOptionsFor(ListItem $item): array
    {
        $options = $this->getTeacherOptions();
        $known   = array_fill_keys(array_map(static fn (Teacher $t): string => $t->getId()->toRfc4122(), $options), true);
        foreach ($item->getTeachers() as $teacher) {
            if (!isset($known[$teacher->getId()->toRfc4122()])) {
                $options[] = $teacher;
            }
        }

        return $options;
    }

    /** Whether $teacher is outside the active course (or inactive): their association counts for nothing — red, like in Asignar perfiles. */
    public function isTeacherOutOfCourse(Teacher $teacher): bool
    {
        return !$this->leafTeachers->isInCourse($teacher, $this->centre);
    }

    /**
     * The teachers behind the badge of an item in the tree: its own and those it inherits, with the names for the tooltip.
     *
     * @return array{own: list<Teacher>, inherited: list<Teacher>, count: int, names: string, allOutOfCourse: bool}
     */
    public function teacherBadge(ListItem $item): array
    {
        $own       = $item->getTeachers();
        $inherited = array_map(static fn (array $pair): Teacher => $pair['teacher'], $item->getInheritedTeachers());
        $all       = [...$own, ...$inherited];
        $names     = array_map(
            fn (Teacher $t): string => $t->getName()->full() . ($this->isTeacherOutOfCourse($t) ? ' (' . $this->t('responsibilities.lists.teachers.out_of_course') . ')' : ''),
            $all,
        );
        $outOfCourse = \count(array_filter($all, fn (Teacher $t): bool => $this->isTeacherOutOfCourse($t)));

        return ['own' => $own, 'inherited' => $inherited, 'count' => \count($names), 'names' => implode(', ', $names), 'allOutOfCourse' => $all !== [] && $outOfCourse === \count($all)];
    }

    /**
     * Resolves picked ids to teachers that may be associated: a teacher of the course or one of $already (those the item has now, so
     * keeping an out-of-course one is possible). Unknown or tampered ids are dropped.
     *
     * @param  array<mixed>  $ids
     * @param  list<Teacher> $already
     * @return list<Teacher>
     */
    private function teachersFor(array $ids, array $already = []): array
    {
        $allowed = [];
        foreach ([...$this->getTeacherOptions(), ...$already] as $teacher) {
            $allowed[$teacher->getId()->toRfc4122()] = $teacher;
        }

        $result = [];
        foreach (array_unique(array_filter($ids, 'is_string')) as $id) {
            if (isset($allowed[$id])) {
                $result[] = $allowed[$id];
            }
        }

        return $result;
    }

    /**
     * Makes $item's own teachers exactly $teachers, touching only what changes (no row is deleted and inserted again).
     *
     * @param list<Teacher> $teachers
     */
    private function setTeachers(ListItem $item, array $teachers): void
    {
        $wanted = [];
        foreach ($teachers as $teacher) {
            $wanted[$teacher->getId()->toRfc4122()] = $teacher;
        }
        foreach ($item->getTeachers() as $current) {
            if (!isset($wanted[$current->getId()->toRfc4122()])) {
                $item->removeTeacher($current);
            }
        }
        foreach ($wanted as $teacher) {
            $item->addTeacher($teacher);
        }
        $this->offCourseMemo = null;
    }

    #[LiveAction]
    public function saveTeachers(): void
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $this->setTeachers($selected, $this->teachersFor($this->teacherIds, $selected->getTeachers()));
        $this->em->flush();
        $this->teacherIds = array_map(static fn (Teacher $t): string => $t->getId()->toRfc4122(), $selected->getTeachers());
        $this->flashSuccess($this->t('responsibilities.lists.teachers.flash.saved'));
    }

    /**
     * What the bulk teachers action would do, said in words before it is pressed («Se añadirá a Noelia Bravo Escudero en 6 elementos.»);
     * null with nothing picked or nothing checked.
     */
    public function getBulkTeacherSummary(): ?string
    {
        $teachers = $this->teachersFor($this->bulkTeacherIds);
        if ($teachers === [] || $this->bulkSelectedIds === []) {
            return null;
        }

        $mode = \in_array($this->bulkTeacherMode, ['add', 'remove', 'replace'], true) ? $this->bulkTeacherMode : 'add';

        return $this->translator->trans('responsibilities.lists.teachers.bulk.summary.' . $mode, [
            '%names%' => implode(', ', array_map(static fn (Teacher $t): string => $t->getName()->full(), $teachers)),
            '%count%' => \count($this->bulkSelectedIds),
        ], 'admin');
    }

    /** Adds, removes or replaces the teachers of $bulkTeacherIds on every checked item (their own: the inherited ones are not theirs to touch). */
    #[LiveAction]
    public function bulkApplyTeachers(): void
    {
        $teachers = $this->teachersFor($this->bulkTeacherIds);
        if ($this->bulkSelectedIds === [] || $teachers === []) {
            return;
        }
        $mode = \in_array($this->bulkTeacherMode, ['add', 'remove', 'replace'], true) ? $this->bulkTeacherMode : 'add';

        $count = 0;
        $index = $this->itemIndex();
        foreach ($this->bulkSelectedIds as $id) {
            $item = $index[(string) $id] ?? null;
            if ($item === null) {
                continue;
            }

            if ($mode === 'replace') {
                $this->setTeachers($item, $teachers);
            } else {
                foreach ($teachers as $teacher) {
                    if ($mode === 'remove') {
                        $item->removeTeacher($teacher);
                    } else {
                        $item->addTeacher($teacher);
                    }
                }
            }
            ++$count;
        }

        $this->offCourseMemo = null;
        $this->em->flush();
        $this->bulkSelectedIds = [];
        $this->bulkTeacherIds  = [];
        ++$this->bulkTeacherRound;
        $this->flashSuccess($this->translator->trans('responsibilities.lists.teachers.bulk.flash.' . $mode, ['%count%' => $count], 'admin'));
    }

    /**
     * Every item of this list (of the centre, in the centre-wide view) with an own teacher that is outside the active course, as the
     * associations to remove — what «Quitar docentes fuera de curso (N)» counts and removes.
     *
     * @return list<array{item: ListItem, teacher: Teacher}>
     */
    private function offCourseAssociations(): array
    {
        return $this->offCourseMemo ??= $this->findOffCourseAssociations();
    }

    /** @var list<array{item: ListItem, teacher: Teacher}>|null read once per render (both headers ask), forgotten whenever teachers change */
    private ?array $offCourseMemo = null;

    /** @return list<array{item: ListItem, teacher: Teacher}> */
    private function findOffCourseAssociations(): array
    {
        // From the tree the repository already holds for this request (no query of its own on every render).
        $items = $this->root !== null ? $this->items->findSubtree($this->root) : $this->items->findAllInTree($this->centre);

        $found = [];
        foreach ($items as $item) {
            foreach ($item->getTeachers() as $teacher) {
                if ($this->isTeacherOutOfCourse($teacher)) {
                    $found[] = ['item' => $item, 'teacher' => $teacher];
                }
            }
        }

        return $found;
    }

    public function getOffCourseTeacherCount(): int
    {
        return \count($this->offCourseAssociations());
    }

    #[LiveAction]
    public function askRemoveOffCourseTeachers(): void
    {
        $this->confirmingRemoveOffYearTeachers = true;
    }

    #[LiveAction]
    public function cancelRemoveOffCourseTeachers(): void
    {
        $this->confirmingRemoveOffYearTeachers = false;
    }

    #[LiveAction]
    public function removeOffCourseTeachers(): void
    {
        $this->confirmingRemoveOffYearTeachers = false;
        $removed = 0;
        foreach ($this->offCourseAssociations() as $pair) {
            $pair['item']->removeTeacher($pair['teacher']);
            ++$removed;
        }
        $this->offCourseMemo = null;
        $this->em->flush();
        $this->loadDetail();
        $this->flashSuccess($this->translator->trans('responsibilities.lists.teachers.flash.off_course_removed', ['%count%' => $removed], 'admin'));
    }

    #[LiveAction]
    public function toggleOnlyWithoutTeacher(): void
    {
        $this->onlyWithoutTeacher = !$this->onlyWithoutTeacher;
    }

    // ── Bulk association ─────────────────────────────────────────────────────

    #[LiveAction]
    public function toggleBulkSelectMode(): void
    {
        $this->bulkSelectMode       = !$this->bulkSelectMode;
        $this->bulkSelectedIds      = [];
        $this->bulkAssociationKeys  = [];
        $this->bulkTeacherIds       = [];
        $this->bulkTeacherMode      = 'add';
        $this->confirmingBulkDelete = false;
        $this->bulkMoveTargetId     = '';
        $this->errors               = [];
        // Editing one item's own detail panel while bulk-selecting several others would be a
        // confusing overlap — close it.
        $this->selectedId           = '';
    }

    /**
     * Adds the profiles/subprofiles in $bulkAssociationKeys to every checked item at once, on top of
     * the ones each already has — the same ListItem::addProfileAssociation() a single-item save
     * uses, just looped and flushed once instead of once per item.
     */
    #[LiveAction]
    public function bulkSaveAssociation(): void
    {
        $rows = $this->associationRowsFor($this->bulkAssociationKeys);
        if ($this->bulkSelectedIds === [] || $rows === []) {
            return;
        }

        $count = 0;
        $index = $this->itemIndex();
        foreach ($this->bulkSelectedIds as $id) {
            $item = $index[(string) $id] ?? null;
            if ($item === null) {
                continue;
            }

            foreach ($rows as $row) {
                $item->addProfileAssociation($row->profile, $row->listItem);
            }
            ++$count;
        }

        $this->em->flush();
        $this->bulkSelectedIds     = [];
        $this->bulkAssociationKeys = [];
        $this->flashSuccess($this->translator->trans('responsibilities.lists.bulk.flash.saved', ['%count%' => $count], 'admin'));
    }

    /** Removes every association from every checked item (their own: what they inherit from an ancestor is not theirs to remove). */
    #[LiveAction]
    public function bulkClearAssociations(): void
    {
        if ($this->bulkSelectedIds === []) {
            return;
        }

        $count = 0;
        $index = $this->itemIndex();
        foreach ($this->bulkSelectedIds as $id) {
            $item = $index[(string) $id] ?? null;
            if ($item === null) {
                continue;
            }

            $item->clearProfileAssociations();
            ++$count;
        }

        $this->em->flush();
        $this->bulkSelectedIds     = [];
        $this->bulkAssociationKeys = [];
        $this->flashSuccess($this->translator->trans('responsibilities.lists.bulk.flash.cleared', ['%count%' => $count], 'admin'));
    }

    #[LiveAction]
    public function askBulkDelete(): void
    {
        if ($this->bulkSelectedIds === []) {
            return;
        }
        $this->confirmingBulkDelete = true;
        $this->errors               = [];
    }

    #[LiveAction]
    public function cancelBulkDelete(): void
    {
        $this->confirmingBulkDelete = false;
    }

    /**
     * Deletes every checked item at once, each along with its own subtree (see deleteSelected()) —
     * same "delete the whole branch" semantics, just for several branches in one action. Checking
     * both a branch and one of its own descendants is fine: only the ancestor is actually removed
     * (removing it already takes the descendant with it), so nothing is double-deleted.
     *
     * Blocked as a whole, with nothing removed, if the item or descendant is in use anywhere in
     * the combined set of branches — a partial bulk delete would leave the survivors' selection
     * state confusing to recover from.
     */
    #[LiveAction]
    public function bulkDeleteSelected(): void
    {
        $this->confirmingBulkDelete = false;
        $roots = $this->topLevelSelectedItems();
        if ($roots === []) {
            $this->bulkSelectedIds = [];

            return;
        }

        $subtree = [];
        foreach ($roots as $root) {
            foreach ($this->items->findSubtree($root) as $node) {
                $subtree[$node->getId()->toRfc4122()] = $node;
            }
        }

        $blocking = $this->usage->blocking($this->centre);
        foreach ($subtree as $node) {
            if (isset($blocking[$node->getId()->toRfc4122()])) {
                $this->errors = ['bulkDelete' => $this->t('responsibilities.lists.bulk.error.delete_in_use')];

                return;
            }
        }

        // Remove deepest descendants first, same reasoning as deleteSelected(): $subtree is built
        // one root's whole (pre-order) findSubtree() at a time, and roots never overlap (see
        // topLevelSelectedItems()), so reversing the combined list still removes every node only
        // after all of its own descendants already are.
        foreach (array_reverse($subtree) as $node) {
            $this->em->remove($node);
        }
        $this->em->flush();

        $count                 = count($roots);
        $this->bulkSelectedIds = [];
        $this->flashSuccess($this->translator->trans('responsibilities.lists.bulk.flash.deleted', ['%count%' => $count], 'admin'));
    }

    /**
     * The checked items themselves, minus any that is a descendant of another checked item —
     * deleting the ancestor already deletes its whole subtree, so keeping both would try to remove
     * the same row twice and would break the disjoint-subtrees assumption bulkDeleteSelected()
     * relies on to reverse-order its combined removal list correctly.
     *
     * @return array<string, ListItem> keyed by id
     */
    private function topLevelSelectedItems(): array
    {
        $checked = [];
        $index   = $this->itemIndex();
        foreach ($this->bulkSelectedIds as $id) {
            $item = $index[(string) $id] ?? null;
            if ($item !== null) {
                $checked[$item->getId()->toRfc4122()] = $item;
            }
        }

        return array_filter($checked, static function (ListItem $item) use ($checked): bool {
            for ($ancestor = $item->getParent(); $ancestor !== null; $ancestor = $ancestor->getParent()) {
                if (isset($checked[$ancestor->getId()->toRfc4122()])) {
                    return false;
                }
            }

            return true;
        });
    }

    // ── Bulk move (reparent) ────────────────────────────────────────────────

    /**
     * The whole centre's tree, flattened depth-first for the bulk-move destination picker, minus
     * every checked item's own subtree — moving a branch into itself, or into one of its own
     * descendants, is never valid, so those options are never even offered (setParent() would also
     * reject it, but hiding it from the dropdown is the friendlier failure mode).
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function getBulkMoveTargetOptions(): array
    {
        $excluded = [];
        foreach ($this->topLevelSelectedItems() as $root) {
            foreach ($this->items->findSubtree($root) as $node) {
                $excluded[$node->getId()->toRfc4122()] = true;
            }
        }

        // Own $byParent map rather than reusing getTree()'s nested structure: PHPStan can express
        // "a flat map of siblings, keyed by parent id" (see buildNodes()) but not a genuinely
        // self-referential tree shape, and flattening a nested structure back out would need one
        // anyway.
        $byParent = [];
        foreach ($this->items->findAllByCentre($this->centre) as $item) {
            $key              = $item->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $item;
        }

        $options = [];
        $this->collectMoveOptions('', 0, $byParent, $excluded, $options);

        return $options;
    }

    /**
     * @param array<string, ListItem[]>                     $byParent
     * @param array<string, true>                           $excluded
     * @param array<int, array{id: string, label: string}>  $options
     */
    private function collectMoveOptions(string $parentKey, int $depth, array $byParent, array $excluded, array &$options): void
    {
        foreach ($byParent[$parentKey] ?? [] as $item) {
            $id = $item->getId()->toRfc4122();
            if (isset($excluded[$id])) {
                continue;
            }

            $options[] = ['id' => $id, 'label' => str_repeat('— ', $depth) . $item->getName()];
            $this->collectMoveOptions($id, $depth + 1, $byParent, $excluded, $options);
        }
    }

    /**
     * Moves every checked item at once into $bulkMoveTargetId ('' = root level), keeping the
     * hierarchy each one already has: only the top-level checked items (see
     * topLevelSelectedItems()) are actually reparented — a checked descendant of another checked
     * item just travels along with its own parent, exactly like it would with a single drag-and-drop
     * move. Relative order among the moved items (by their current position) is preserved; they're
     * appended after the destination's existing children.
     */
    #[LiveAction]
    public function bulkMoveSelected(): void
    {
        $this->moveSelected(false);
    }

    /** Moves for real after the warning bulkMoveSelected() raised — only if the selection and target are still the ones warned about. */
    #[LiveAction]
    public function confirmBulkMove(): void
    {
        $this->moveSelected($this->bulkMoveConfirmKey !== '' && $this->bulkMoveConfirmKey === $this->getBulkMoveKey());
    }

    #[LiveAction]
    public function cancelBulkMove(): void
    {
        $this->bulkMoveConfirmKey = '';
    }

    /** Identifies the pending move (destination + checked items), so a confirmation never carries over to a different one. */
    public function getBulkMoveKey(): string
    {
        $ids = array_map('strval', $this->bulkSelectedIds);
        sort($ids);

        return $this->bulkMoveTargetId . '|' . implode(',', $ids);
    }

    private function moveSelected(bool $confirmed): void
    {
        $this->bulkMoveConfirmKey = '';
        $roots                    = $this->topLevelSelectedItems();
        if ($roots === []) {
            return;
        }

        $target = $this->bulkMoveTargetId === '' ? null : $this->items->findByIdAndCentre($this->bulkMoveTargetId, $this->centre);
        if ($this->bulkMoveTargetId !== '' && $target === null) {
            return;
        }

        $ordered = array_values($roots);
        usort($ordered, static fn (ListItem $a, ListItem $b): int => $a->getPosition() <=> $b->getPosition());

        if (!$confirmed && ($this->moveChangesProfiles($ordered, $target) || $this->formUsageOf($ordered) > 0)) {
            $this->bulkMoveConfirmKey = $this->getBulkMoveKey();
            $this->errors             = [];

            return;
        }

        foreach ($ordered as $item) {
            try {
                $item->setParent($target);
            } catch (\LogicException) {
                $this->errors = ['bulkMove' => $this->t('responsibilities.lists.bulk.error.move_invalid')];

                return;
            }
        }

        $position = $target === null ? $this->items->nextRootPosition($this->centre) : $this->items->nextChildPosition($target);
        foreach ($ordered as $item) {
            $item->setPosition($position);
            ++$position;
        }

        $this->em->flush();

        $count                  = count($ordered);
        $this->bulkSelectedIds  = [];
        $this->bulkMoveTargetId = '';
        $this->flashSuccess($target === null
            ? $this->translator->trans('responsibilities.lists.bulk.flash.moved_to_root', ['%count%' => $count], 'admin')
            : $this->translator->trans('responsibilities.lists.bulk.flash.moved', ['%count%' => $count, '%target%' => $target->getName()], 'admin'));
    }

    /**
     * Whether moving these branches under $target changes which profiles — or which teachers — apply to anything in them:
     * everything in the branch inherits the associations and the teachers of all its ancestors, and a profile's
     * subprofiles are the descendants of the item it takes its list from — either one differs once
     * the branch has a different chain of ancestors.
     *
     * @param ListItem[] $roots
     */
    private function moveChangesProfiles(array $roots, ?ListItem $target): bool
    {
        $targetKeys     = $this->associationKeysOfChain($target);
        $targetTeachers = $this->teacherIdsOfChain($target);
        $targetLists    = $this->profileListAncestors($target);
        /** @var array<string, array<string, true>> $chains the chain of each current parent, computed once */
        $chains = [];
        /** @var array<string, array<string, true>> $teacherChains same, for the teachers */
        $teacherChains = [];

        foreach ($roots as $root) {
            $parent = $root->getParent();
            if ($this->profileListAncestors($parent) !== $targetLists) {
                return true;
            }

            $chain      = $chains[$parent?->getId()->toRfc4122() ?? ''] ??= $this->associationKeysOfChain($parent);
            $differing  = array_diff_key($chain, $targetKeys) + array_diff_key($targetKeys, $chain);
            $teacherChain = $teacherChains[$parent?->getId()->toRfc4122() ?? ''] ??= $this->teacherIdsOfChain($parent);
            $differingTeachers = array_diff_key($teacherChain, $targetTeachers) + array_diff_key($targetTeachers, $teacherChain);
            if ($differing === [] && $differingTeachers === []) {
                continue;
            }

            // A node's own associations (and teachers), and those of its ancestors inside the branch, are
            // there before and after: only a node missing some of the keys that differ changes.
            $base         = [];
            $baseTeachers = [];
            foreach ($this->items->findSubtree($root) as $node) {
                $parentId = spl_object_id($node->getParent() ?? $node);
                $mine     = $node === $root ? [] : ($base[$parentId] ?? []);
                foreach ($node->getProfileAssociations() as $association) {
                    $mine[$association->getKey()] = true;
                }
                $base[spl_object_id($node)] = $mine;

                $mineTeachers = $node === $root ? [] : ($baseTeachers[$parentId] ?? []);
                foreach ($node->getTeachers() as $teacher) {
                    $mineTeachers[$teacher->getId()->toRfc4122()] = true;
                }
                $baseTeachers[spl_object_id($node)] = $mineTeachers;

                if (array_diff_key($differing, $mine) !== [] || array_diff_key($differingTeachers, $mineTeachers) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The ids of the teachers of $item and of all its ancestors: who a branch hung under $item inherits.
     *
     * @return array<string, true>
     */
    private function teacherIdsOfChain(?ListItem $item): array
    {
        $ids = [];
        foreach ($item?->getEffectiveTeachers() ?? [] as $teacher) {
            $ids[$teacher->getId()->toRfc4122()] = true;
        }

        return $ids;
    }

    /**
     * The (profile, subprofile) keys of $item's and all its ancestors' associations: what a branch
     * hung under $item inherits.
     *
     * @return array<string, true>
     */
    private function associationKeysOfChain(?ListItem $item): array
    {
        $keys = [];
        if ($item !== null) {
            foreach ($item->getEffectiveProfileAssociations() as $association) {
                $keys[$association->getKey()] = true;
            }
        }

        return $keys;
    }

    /**
     * Ids of $item and its ancestors that a profile takes its list from, in a stable order.
     *
     * @return string[]
     */
    private function profileListAncestors(?ListItem $item): array
    {
        $this->profileListIds ??= $this->usage->profileLists($this->centre);
        $ids = [];
        for ($node = $item; $node !== null; $node = $node->getParent()) {
            if (isset($this->profileListIds[$node->getId()->toRfc4122()])) {
                $ids[] = $node->getId()->toRfc4122();
            }
        }
        sort($ids);

        return $ids;
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
