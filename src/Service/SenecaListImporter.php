<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Model\SenecaImportNode;
use App\Model\SenecaListImportPlan;
use App\Model\SenecaListImportPlanItem;
use App\Repository\DocumentRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rebuilds one Responsabilidades › Listas root — one that a Séneca "groups" or "subjects" CSV
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
        private readonly SpecificProfileRepository $profiles,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly DocumentRepository $documents,
    ) {}

    /** @param SenecaImportNode[] $desired */
    public function plan(EducationalCentre $centre, string $rootName, array $desired): SenecaListImportPlan
    {
        $root = $this->findRootByName($centre, $rootName);

        $additions     = [];
        $deletions     = [];
        $deactivations = [];
        $reactivations = [];

        $this->diffChildren(
            $root !== null ? $this->items->findChildrenByParent($root) : [],
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

        $counts = ['added' => 0, 'deleted' => 0, 'deactivated' => 0, 'reactivated' => 0];
        $this->applyChildren($root, $centre, $desired, $deleteUnused, $counts);
        $this->em->flush();

        return $counts;
    }

    // ── plan (read-only) ─────────────────────────────────────────────────────

    /**
     * @param ListItem[]                  $existingChildren
     * @param SenecaImportNode[]          $desired
     * @param string[]                    $path
     * @param SenecaListImportPlanItem[]  $additions
     * @param SenecaListImportPlanItem[]  $deletions
     * @param SenecaListImportPlanItem[]  $deactivations
     * @param SenecaListImportPlanItem[]  $reactivations
     */
    private function diffChildren(
        array $existingChildren,
        array $desired,
        array $path,
        array &$additions,
        array &$deletions,
        array &$deactivations,
        array &$reactivations,
    ): void {
        $existingByName = [];
        foreach ($existingChildren as $child) {
            $existingByName[mb_strtolower($child->getName())] = $child;
        }

        foreach ($desired as $node) {
            $key       = mb_strtolower($node->name);
            $match     = $existingByName[$key] ?? null;
            $childPath = [...$path, $node->name];
            unset($existingByName[$key]);

            if ($match === null) {
                $this->collectAdditions($node, $childPath, $additions);

                continue;
            }

            if (!$match->isActive()) {
                $reactivations[] = new SenecaListImportPlanItem($childPath, $match);
            }

            // A repository query, not $match->getChildren() — see planRemoval()'s comment on why.
            $this->diffChildren(
                $this->items->findChildrenByParent($match),
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
        $childrenDeletable = true;
        // A repository query, not $item->getChildren(): $item may be an identity-map hit whose
        // in-memory collection was never hydrated from the database (e.g. an entity built and
        // persisted, but never queried, earlier in the same request).
        foreach ($this->items->findChildrenByParent($item) as $child) {
            if (!$this->planRemoval($child, [...$path, $child->getName()], $deletions, $deactivations)) {
                $childrenDeletable = false;
            }
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
        foreach ($this->items->findChildrenByParent($parent) as $child) {
            $existingByName[mb_strtolower($child->getName())] = $child;
        }

        $position = 0;
        foreach ($desired as $node) {
            $key   = mb_strtolower($node->name);
            $match = $existingByName[$key] ?? null;
            unset($existingByName[$key]);

            if ($match === null) {
                $match = new ListItem();
                $match->setEducationalCentre($centre);
                $match->setParent($parent);
                $match->setName($node->name);
                $match->setPosition($position);
                $this->em->persist($match);
                ++$counts['added'];
            } else {
                if (!$match->isActive()) {
                    $match->setActive(true);
                    ++$counts['reactivated'];
                }
                $match->setPosition($position);
            }
            ++$position;

            $this->applyChildren($match, $centre, $node->getChildren(), $deleteUnused, $counts);
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
        $childrenRemoved = true;
        // A repository query, not $item->getChildren() — see planRemoval()'s identical comment;
        // this also sidesteps needing to snapshot a live Collection before mutating it below.
        foreach ($this->items->findChildrenByParent($item) as $child) {
            if (!$this->applyRemoval($child, $deleteUnused, $counts)) {
                $childrenRemoved = false;
            }
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
        return $this->profiles->isListItemInUse($item)
            || $this->assignments->isListItemAssigned($item)
            || $this->documents->isListItemUsedByDocument($item);
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
