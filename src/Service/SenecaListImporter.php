<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\ListItemTeacher;
use App\Entity\Teacher;
use App\Model\SenecaImportNode;
use App\Model\SenecaListImportPlan;
use App\Model\SenecaListImportPlanItem;
use App\Model\SenecaListImportTeacherChange;
use App\Model\SenecaSubjectsLayout;
use App\Model\SenecaTeacherMatch;
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
 *
 * With the teachers of a subjects import (a SenecaTeacherMatch), each element of the file whose
 * node takes teachers ends up with exactly the teachers the file names for it that were found:
 * its own associations are diffed with them (never cleared and re-added, which would hit the
 * unique constraint in the same flush), those of the elements the file does not mention are left
 * alone, and profile associations are never touched.
 *
 * $searchDeeper = false turns off looking for items inside categories: when the list is imported
 * with the other layout (by subject a list made by group, or the opposite), a subject would
 * otherwise "match" the subject leaf of some group — the import must create the new layout and
 * remove the old one instead (see detectLayout()).
 *
 * @phpstan-type ImportCounts array{added: int, deleted: int, deactivated: int, reactivated: int, teachers_added: int, teachers_removed: int}
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
     * The layout the list under $rootName already has, from how many of its first-level names are groups («Unidad») and how
     * many are subjects («Materia») of the file; on a tie (a list organised in categories), from its leaves — subjects as
     * leaves is by group, groups as leaves is by subject. Null when there is no such list, it is empty or nothing tells.
     *
     * @param array<string, true> $units    the lower-cased «Unidad» values of the file
     * @param array<string, true> $subjects the lower-cased «Materia» values of the file
     */
    public function detectLayout(EducationalCentre $centre, string $rootName, array $units, array $subjects): ?SenecaSubjectsLayout
    {
        $root = $this->findRootByName($centre, $rootName);
        if ($root === null) {
            return null;
        }

        $this->items->reset();
        $byParent = $this->items->childrenMap($centre);
        $first= array_values(array_map(static fn (ListItem $i): string => mb_strtolower($i->getName()), $byParent[$root->getId()->toRfc4122()] ?? []));
        $byTop = self::layoutOfNames($first, $units, $subjects);
        if ($byTop !== null) {
            return $byTop;
        }

        $leaves = [];
        $queue  = $byParent[$root->getId()->toRfc4122()] ?? [];
        while ($queue !== []) {
            $item     = array_shift($queue);
            $children = $byParent[$item->getId()->toRfc4122()] ?? [];
            if ($children === []) {
                $leaves[] = mb_strtolower($item->getName());
            }
            array_push($queue, ...$children);
        }

        // Leaves that are groups mean a list by subject, and the other way round.
        return match (self::layoutOfNames($leaves, $units, $subjects)) {
            SenecaSubjectsLayout::ByGroup   => SenecaSubjectsLayout::BySubject,
            SenecaSubjectsLayout::BySubject => SenecaSubjectsLayout::ByGroup,
            null                            => null,
        };
    }

    /**
     * ByGroup when more of $names are groups than subjects, BySubject the other way round, null on a tie.
     *
     * @param list<string>        $names
     * @param array<string, true> $units
     * @param array<string, true> $subjects
     */
    private static function layoutOfNames(array $names, array $units, array $subjects): ?SenecaSubjectsLayout
    {
        $u = $s = 0;
        foreach ($names as $name) {
            $u += isset($units[$name]) ? 1 : 0;
            $s += isset($subjects[$name]) ? 1 : 0;
        }

        return $u === $s ? null : ($u > $s ? SenecaSubjectsLayout::ByGroup : SenecaSubjectsLayout::BySubject);
    }

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
    public function plan(
        EducationalCentre $centre,
        string $rootName,
        array $desired,
        ?SenecaTeacherMatch $teachers = null,
        bool $searchDeeper = true,
    ): SenecaListImportPlan {
        $root = $this->findRootByName($centre, $rootName);
        $this->loadUsage($centre, $root, $teachers, $searchDeeper);

        $additions     = [];
        $deletions     = [];
        $deactivations = [];
        $reactivations = [];
        $this->teacherChanges = [];
        $this->teacherLinks   = 0;

        $this->diffChildren(
            $root,
            $desired,
            [],
            $additions,
            $deletions,
            $deactivations,
            $reactivations,
        );

        return new SenecaListImportPlan(
            $rootName,
            $root !== null,
            $additions,
            $deletions,
            $deactivations,
            $reactivations,
            $this->teacherChanges,
            $this->teacherLinks,
            $teachers,
        );
    }

    /**
     * @param  SenecaImportNode[] $desired
     * @return ImportCounts
     */
    public function apply(
        EducationalCentre $centre,
        string $rootName,
        array $desired,
        bool $deleteUnused,
        ?SenecaTeacherMatch $teachers = null,
        bool $searchDeeper = true,
    ): array {
        $root = $this->findRootByName($centre, $rootName);
        if ($root === null) {
            $root = new ListItem();
            $root->setEducationalCentre($centre);
            $root->setName($rootName);
            $root->setPosition($this->items->nextRootPosition($centre));
            $this->em->persist($root);
        }

        $this->loadUsage($centre, $root, $teachers, $searchDeeper);
        $counts = ['added' => 0, 'deleted' => 0, 'deactivated' => 0, 'reactivated' => 0, 'teachers_added' => 0, 'teachers_removed' => 0];
        $this->applyChildren($root, $centre, $desired, $deleteUnused, $counts);
        $this->em->flush();

        return $counts;
    }

    // ── teachers ────────────────────────────────────────────────────────────

    /** @var SenecaListImportTeacherChange[] filled by a plan */
    private array $teacherChanges = [];

    /** the element–teacher pairs of the file that were found, counted by a plan */
    private int $teacherLinks = 0;

    private ?SenecaTeacherMatch $teacherMatch = null;

    private bool $searchDeeper = true;

    /** @var array<string, list<ListItemTeacher>> the own teacher associations of the centre's items, by item id (read once, only with teachers) */
    private array $ownTeachers = [];

    /**
     * What the file says about the teachers of $node, against the own associations of $item (null: an element the import creates):
     * the associations to drop and the teachers to add. Null when the import does not handle teachers or the node takes none.
     *
     * @return array{keep: list<Teacher>, drop: list<ListItemTeacher>, add: list<Teacher>}|null
     */
    private function teacherDiff(SenecaImportNode $node, ?ListItem $item): ?array
    {
        if ($this->teacherMatch === null || !$node->takesTeachers()) {
            return null;
        }

        $wanted  = $this->teacherMatch->teachersFor($node->getTeacherNames());
        $current = $item !== null ? $this->ownTeachers[$item->getId()->toRfc4122()] ?? [] : [];

        $drop = array_values(array_filter($current, static fn (ListItemTeacher $a): bool => !LeafTeachers::isAmong($a->getTeacher(), $wanted)));
        $have = array_map(static fn (ListItemTeacher $a): Teacher => $a->getTeacher(), $current);
        $add  = array_values(array_filter($wanted, static fn (Teacher $t): bool => !LeafTeachers::isAmong($t, $have)));

        return ['keep' => $wanted, 'drop' => $drop, 'add' => $add];
    }

    /** @param string[] $path */
    private function planTeachers(SenecaImportNode $node, ?ListItem $item, array $path): void
    {
        $diff = $this->teacherDiff($node, $item);
        if ($diff === null) {
            return;
        }

        $this->teacherLinks += count($diff['keep']);
        if ($diff['add'] !== [] || $diff['drop'] !== []) {
            $this->teacherChanges[] = new SenecaListImportTeacherChange(
                $path,
                array_map(static fn (Teacher $t): string => $t->getName()->full(), $diff['add']),
                array_map(static fn (ListItemTeacher $a): string => $a->getTeacher()->getName()->full(), $diff['drop']),
            );
        }
    }

    /** @param ImportCounts $counts */
    private function applyTeachers(SenecaImportNode $node, ListItem $item, bool $isNew, array &$counts): void
    {
        $diff = $this->teacherDiff($node, $isNew ? null : $item);
        if ($diff === null) {
            return;
        }

        foreach ($diff['drop'] as $association) {
            $item->removeTeacher($association->getTeacher());
            ++$counts['teachers_removed'];
        }
        foreach ($diff['add'] as $teacher) {
            $item->addTeacher($teacher);
            ++$counts['teachers_added'];
        }
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
            $this->planTeachers($node, $match, $childPath);

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
        $this->planTeachers($node, null, $path);
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
     * @param SenecaImportNode[] $desired
     * @param ImportCounts       $counts
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

            $isNew = $match === null;
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
            $this->applyTeachers($node, $match, $isNew, $counts);
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
     * @param  ImportCounts $counts
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
        if (!$this->searchDeeper) {
            return null;
        }

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
    private function loadUsage(EducationalCentre $centre, ?ListItem $root, ?SenecaTeacherMatch $teachers, bool $searchDeeper): void
    {
        $this->items->reset();
        $this->claimed = $this->claimedAncestors = [];
        $this->teacherMatch = $teachers;
        $this->searchDeeper = $searchDeeper;
        $this->ownTeachers  = $teachers !== null ? $this->items->teacherAssociationsByItem($centre) : [];
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
