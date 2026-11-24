<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Model\SenecaImportNode;
use App\Model\SenecaListImportPlan;
use App\Model\SenecaListImportPlanItem;
use App\Repository\ListItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rebuilds one Responsabilidades › Catálogo del centro root — one that a Séneca "groups" or "subjects" CSV
 * import is about to fill (see SenecaListCsvParser) — to match the CSV, without a full replace
 * like ListItemJsonImporter's: existing items matching the CSV by name (case-insensitively, at
 * each level) are kept and reordered to the CSV's order; items the CSV no longer mentions are
 * deleted if nothing references them, or deactivated if something does (a profile association, a
 * teacher assignment, or a delivered document — see isInUse()); a currently-inactive item that
 * reappears is reactivated. A parent can only be deleted once every one of its own children has
 * also been resolved as deletable, so an in-use leaf keeps its whole ancestor chain around too
 * (deactivated, not deleted).
 *
 * plan() computes this without touching the database, for the confirmation preview; apply() does
 * the same walk again against freshly-loaded entities and actually persists it — the two are
 * intentionally independent passes (not one plan reused for both) since a fresh apply() call is
 * the only way to be sure nothing changed underneath a preview the user took a moment to read
 * before confirming.
 */
final class SenecaListImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListItemRepository $items,
        private readonly ListItemUsage $usage,
        private readonly \App\Repository\FormValueRepository $formValues,
    ) {}

    /**
     * In how many delivered documents of the models filled in online the items the import would remove or deactivate appear: those
     * documents keep the names they were delivered with, but the items stop being offered and the drafts that have them chosen cannot
     * be delivered until they pick another one.
     */
    public function formUsage(SenecaListImportPlan $plan): int
    {
        $ids = [];
        foreach ([...$plan->deletions, ...$plan->deactivations] as $row) {
            if ($row->item !== null) {
                $ids[] = $row->item->getId()->toRfc4122();
            }
        }

        return $ids === [] ? 0 : $this->formValues->countDocumentsUsingItems($ids);
    }

    /** @param SenecaImportNode[] $desired */
    public function plan(EducationalCentre $centre, string $rootName, array $desired): SenecaListImportPlan
    {
        $root = $this->findRootByName($centre, $rootName);
        $this->loadUsage($centre, $root);

        $additions     = [];
        $deletions     = [];
        $deactivations = [];
        $reactivations = [];

        $this->diffChildren(
            $root,
            $desired,
            [],
            $additions,
            $deletions,
            $deactivations,
            $reactivations,
        );

        return new SenecaListImportPlan($rootName, $root !== null, $additions, $deletions, $deactivations, $reactivations);
    }

    /**
     * @param  SenecaImportNode[] $desired
     * @return array{added: int, deleted: int, deactivated: int, reactivated: int}
     */
    public function apply(EducationalCentre $centre, string $rootName, array $desired, bool $deleteUnused): array
    {
        $root = $this->findRootByName($centre, $rootName);
        if ($root === null) {
            $root = new ListItem();
            $root->setEducationalCentre($centre);
            $root->setName($rootName);
            $root->setPosition($this->items->nextRootPosition($centre));
            $this->em->persist($root);
        }

        $this->loadUsage($centre, $root);
        $counts = ['added' => 0, 'deleted' => 0, 'deactivated' => 0, 'reactivated' => 0];
        $this->applyChildren($root, $centre, $desired, $deleteUnused, $counts);
        $this->em->flush();

        return $counts;
    }

    // ── plan (read-only) ─────────────────────────────────────────────────────

    /**
     * @param SenecaImportNode[]          $desired
     * @param string[]                    $path
     * @param SenecaListImportPlanItem[]  $additions
     * @param SenecaListImportPlanItem[]  $deletions
     * @param SenecaListImportPlanItem[]  $deactivations
     * @param SenecaListImportPlanItem[]  $reactivations
     */
    private function diffChildren(
        ?ListItem $parent,
        array $desired,
        array $path,
        array &$additions,
        array &$deletions,
        array &$deactivations,
        array &$reactivations,
    ): void {
        $existingByName = [];
        foreach ($parent !== null ? $this->children($parent) : [] as $child) {
            $existingByName[mb_strtolower($child->getName())] = $child;
        }

        foreach ($desired as $node) {
            $key       = mb_strtolower($node->name);
            $match     = $existingByName[$key] ?? null;
            $childPath = [...$path, $node->name];
            unset($existingByName[$key]);

            // Not a child of its own: the list may have been organised in categories, so look further down.
            $match ??= $parent !== null ? $this->findDeeper($parent, $node) : null;
            if ($match !== null) {
                $this->claim($match);
            }

            if ($match === null) {
                $this->collectAdditions($node, $childPath, $additions);

                continue;
            }

            if (!$match->isActive()) {
                $reactivations[] = new SenecaListImportPlanItem($childPath, $match);
            }

            // A repository query, not $match->getChildren() — see planRemoval()'s comment on why.
            $this->diffChildren(
                $match,
                $node->getChildren(),
                $childPath,
                $additions,
                $deletions,
                $deactivations,
                $reactivations,
            );
        }

        foreach ($existingByName as $orphan) {
            $this->planRemoval($orphan, [...$path, $orphan->getName()], $deletions, $deactivations);
        }
    }

    /**
     * @param string[]                   $path
     * @param SenecaListImportPlanItem[] $additions
     */
    private function collectAdditions(SenecaImportNode $node, array $path, array &$additions): void
    {
        $additions[] = new SenecaListImportPlanItem($path);
        foreach ($node->getChildren() as $child) {
            $this->collectAdditions($child, [...$path, $child->name], $additions);
        }
    }

    /**
     * @param  string[]                   $path
     * @param  SenecaListImportPlanItem[] $deletions
     * @param  SenecaListImportPlanItem[] $deactivations
     * @return bool whether $item (and everything under it) is unused, i.e. eligible to be deleted
     */
    private function planRemoval(ListItem $item, array $path, array &$deletions, array &$deactivations): bool
    {
        // Matched by the import further down the tree (inside a category): it stays where it is, with what is under it.
        if (isset($this->claimed[$item->getId()->toRfc4122()])) {
            return false;
        }

        $childrenDeletable = true;
        // A repository query, not $item->getChildren(): $item may be an identity-map hit whose
        // in-memory collection was never hydrated from the database (e.g. an entity built and
        // persisted, but never queried, earlier in the same request).
        foreach ($this->children($item) as $child) {
            if (!$this->planRemoval($child, [...$path, $child->getName()], $deletions, $deactivations)) {
                $childrenDeletable = false;
            }
        }

        // A category that holds something the import matched is not removed or deactivated: its other items already were.
        if (isset($this->claimedAncestors[$item->getId()->toRfc4122()])) {
            return false;
        }

        if ($childrenDeletable && !$this->isInUse($item)) {
            $deletions[] = new SenecaListImportPlanItem($path, $item);

            return true;
        }

        if ($item->isActive()) {
            $deactivations[] = new SenecaListImportPlanItem($path, $item);
        }

        return false;
    }

    // ── apply (mutating) ────────────────────────────────────────────────────

    /**
     * @param SenecaImportNode[]                            $desired
     * @param array{added: int, deleted: int, deactivated: int, reactivated: int} $counts
     */
    private function applyChildren(ListItem $parent, EducationalCentre $centre, array $desired, bool $deleteUnused, array &$counts): void
    {
        $existingByName = [];
        foreach ($this->children($parent) as $child) {
            $existingByName[mb_strtolower($child->getName())] = $child;
        }

        /** @var ListItem[] $placed the direct children the CSV names, in its order */
        $placed = [];
        foreach ($desired as $node) {
            $key   = mb_strtolower($node->name);
            $match = $existingByName[$key] ?? null;
            unset($existingByName[$key]);

            // Not a child of its own: the list may have been organised in categories, so look further down.
            $deep  = $match === null ? $this->findDeeper($parent, $node) : null;
            $match ??= $deep;
            if ($match !== null) {
                $this->claim($match);
            }

            if ($match === null) {
                $match = new ListItem();
                $match->setEducationalCentre($centre);
                $match->setParent($parent);
                $match->setName($node->name);
                $this->em->persist($match);
                ++$counts['added'];
            } elseif (!$match->isActive()) {
                $match->setActive(true);
                ++$counts['reactivated'];
            }
            // One found inside a category keeps its place there: only the direct children follow the CSV's order.
            if ($deep === null) {
                $placed[] = $match;
            }

            $this->applyChildren($match, $centre, $node->getChildren(), $deleteUnused, $counts);
        }

        // After the categories that were kept because they hold something the import matched (none, in a list without them).
        $position = 0;
        foreach ($this->children($parent) as $child) {
            if (isset($this->claimedAncestors[$child->getId()->toRfc4122()])) {
                $position = max($position, $child->getPosition() + 1);
            }
        }
        foreach ($placed as $item) {
            $item->setPosition($position++);
        }

        foreach ($existingByName as $orphan) {
            $this->applyRemoval($orphan, $deleteUnused, $counts);
        }
    }

    /**
     * @param  array{added: int, deleted: int, deactivated: int, reactivated: int} $counts
     * @return bool whether $item ended up deleted (vs. kept, deactivated)
     */
    private function applyRemoval(ListItem $item, bool $deleteUnused, array &$counts): bool
    {
        // See planRemoval(): matched further down the tree, it stays; a category that holds one is kept too.
        if (isset($this->claimed[$item->getId()->toRfc4122()])) {
            return false;
        }

        $childrenRemoved = true;
        // A repository query, not $item->getChildren() — see planRemoval()'s identical comment;
        // this also sidesteps needing to snapshot a live Collection before mutating it below.
        foreach ($this->children($item) as $child) {
            if (!$this->applyRemoval($child, $deleteUnused, $counts)) {
                $childrenRemoved = false;
            }
        }

        if (isset($this->claimedAncestors[$item->getId()->toRfc4122()])) {
            return false;
        }

        if ($childrenRemoved && $deleteUnused && !$this->isInUse($item)) {
            $this->em->remove($item);
            ++$counts['deleted'];

            return true;
        }

        if ($item->isActive()) {
            $item->setActive(false);
            ++$counts['deactivated'];
        }

        return false;
    }

    private function isInUse(ListItem $item): bool
    {
        // In use by a profile, an assignment or an uploaded document — or chosen in a delivered document of a model filled in online: it
        // is deactivated, not deleted, so it keeps its name there. All read in bulk, once per plan or apply (see loadUsage()).
        return isset($this->usedItems[$item->getId()->toRfc4122()]);
    }

    /** @var array<string, true> the items of the centre that cannot be deleted (read once per plan or apply) */
    private array $usedItems = [];

    /** @var array<string, true> the items the import matched (by name, at any depth) in this plan or apply */
    private array $claimed = [];

    /** @var array<string, true> the ancestors of those: categories that must not be removed or deactivated */
    private array $claimedAncestors = [];

    private function claim(ListItem $item): void
    {
        $this->claimed[$item->getId()->toRfc4122()] = true;
        for ($up = $item->getParent(); $up !== null; $up = $up->getParent()) {
            $this->claimedAncestors[$up->getId()->toRfc4122()] = true;
        }
    }

    /**
     * An item with the node's name under one of the children of $parent (or deeper still), not matched yet: the list may have its
     * groups, or its subjects, organised in categories. A node with nothing under it (a group, or a subject) only matches a leaf.
     */
    private function findDeeper(ListItem $parent, SenecaImportNode $node): ?ListItem
    {
        $needle   = mb_strtolower($node->name);
        $wantLeaf = $node->getChildren() === [];
        $queue    = $this->children($parent);
        while ($queue !== []) {
            $category = array_shift($queue);
            if (isset($this->claimed[$category->getId()->toRfc4122()])) {
                continue;
            }
            foreach ($this->children($category) as $candidate) {
                if (!isset($this->claimed[$candidate->getId()->toRfc4122()])
                    && mb_strtolower($candidate->getName()) === $needle
                    && (!$wantLeaf || $this->children($candidate) === [])) {
                    return $candidate;
                }
                $queue[] = $candidate;
            }
        }

        return null;
    }

    /** @var array<string, ListItem[]> the centre's tree by parent id (the roots under ''), read once per plan or apply */
    private array $childMap = [];

    /** @return ListItem[] the children of an item, from the tree already read (a node made by this import has none yet) */
    private function children(ListItem $parent): array
    {
        return $this->childMap[$parent->getId()->toRfc4122()] ?? [];
    }

    /**
     * What a plan or an apply reads about the centre, ONCE: its tree and what is in use. Without this, every item of the list costs
     * its own queries (its children, and a profile, an assignment, a document and a delivered form check).
     */
    private function loadUsage(EducationalCentre $centre, ?ListItem $root): void
    {
        $this->items->reset();
        $this->claimed = $this->claimedAncestors = [];
        $this->childMap = $this->items->childrenMap($centre);
        $this->usedItems = $this->usage->keptByImports($centre);
        if ($root !== null && $this->em->contains($root)) {
            $ids = array_values(array_map(static fn (ListItem $node): string => $node->getId()->toRfc4122(), $this->items->findSubtree($root)));
            $this->usedItems += $this->formValues->findItemIdsInUse($ids);
        }
    }

    private function findRootByName(EducationalCentre $centre, string $name): ?ListItem
    {
        $needle = mb_strtolower($name);
        foreach ($this->items->findRootsByCentre($centre) as $root) {
            if (mb_strtolower($root->getName()) === $needle) {
                return $root;
            }
        }

        return null;
    }
}
