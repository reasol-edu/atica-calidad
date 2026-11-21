<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\Teacher;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

/**
 * Category navigation of ActivityBrowserComponent: current level, breadcrumb, category tree and the activities listed in the current category.
 */
trait ActivityCategoryNavigation
{
    /** @var ActivityCategory[]|null memoised per render — see getCategoryTree() */
    private ?array $allCategoriesCache = null;

    // ── Category navigation ──────────────────────────────────────────────────

    public function getCurrentCategory(): ?ActivityCategory
    {
        if ($this->currentCategoryId === '') {
            return null;
        }

        return $this->categories->findByIdAndCentre($this->currentCategoryId, $this->centre);
    }

    /** @return ActivityCategory[] */
    public function getVisibleCategories(): array
    {
        $parent = $this->getCurrentCategory();
        $all    = $parent === null
            ? $this->categories->findRootsByCentre($this->centre)
            : $this->categories->findChildrenByParent($parent);

        if ($this->showAllProfiles) {
            return $all;
        }

        $teacher = $this->teacher();

        return array_values(array_filter($all, fn (ActivityCategory $c): bool => $this->categoryHasRelevantActivity($c, $teacher)));
    }

    /** @return ActivityCategory[] root-first path of ancestors down to (and including) the current category */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentCategory(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    #[LiveAction]
    public function openLevel(#[LiveArg] string $id): void
    {
        $this->currentCategoryId = $id;
        $this->resetTransientState();
        $this->dispatchCategoryLocation();
    }

    /**
     * Restores $currentCategoryId from the URL after the user hits the browser's back/forward
     * button — the counterpart to dispatchCategoryLocation()'s pushState on the JS side. Mirrors
     * SectionBrowserComponent::syncFromUrl(), scoped to just category navigation (not the rest of
     * the screen's transient state, which the URL never tracks here). Never dispatches the
     * location event itself, or every back/forward press would push a new (forward) history entry.
     */
    #[LiveAction]
    public function syncCategoryFromUrl(#[LiveArg] string $category = ''): void
    {
        $this->currentCategoryId = $category !== '' && $this->categories->findByIdAndCentre($category, $this->centre) !== null
            ? $category
            : '';
        $this->resetTransientState();
    }

    /** Tells the activity-category-url Stimulus controller to reflect the current category in the URL (pushState). */
    private function dispatchCategoryLocation(): void
    {
        $this->dispatchBrowserEvent('activity-category:location', [
            'category' => $this->currentCategoryId,
        ]);
    }

    #[LiveAction]
    public function toggleShowAllProfiles(): void
    {
        $this->showAllProfiles = !$this->showAllProfiles;
    }

    /** Whether $category, or any of its descendants, has at least one activity relevant to $teacher. */
    private function categoryHasRelevantActivity(ActivityCategory $category, Teacher $teacher): bool
    {
        foreach ($this->activities->findByCategory($category, $this->canEdit()) as $activity) {
            if ($this->access->isActivityRelevantToTeacher($teacher, $activity)) {
                return true;
            }
        }
        foreach ($this->categories->findChildrenByParent($category) as $child) {
            if ($this->categoryHasRelevantActivity($child, $teacher)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole category tree, for the sidebar shown alongside the breadcrumb/cards browsing on
     * wide screens (mirrors SectionBrowserComponent::getSectionTree()) — every category of the
     * centre (any teacher may browse the full tree, there's no per-category access gate the way
     * DocumentSection has), pruned to ones with a relevant activity (in themselves or a descendant)
     * when showAllProfiles is off, exactly like getVisibleCategories() prunes the card grid.
     *
     * @return array<int, array{category: ActivityCategory, children: array<mixed>}>
     */
    public function getCategoryTree(): array
    {
        $byParent = [];
        foreach ($this->allCategoriesForTree() as $category) {
            $key              = $category->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $category;
        }

        return $this->buildCategoryTreeNodes('', $byParent);
    }

    /** @return ActivityCategory[] every category of the centre, loaded once per render. */
    private function allCategoriesForTree(): array
    {
        return $this->allCategoriesCache ??= $this->categories->findAllByCentre($this->centre);
    }

    /**
     * @param array<string, ActivityCategory[]> $byParent
     *
     * @return array<int, array{category: ActivityCategory, children: array<mixed>}>
     */
    private function buildCategoryTreeNodes(string $parentKey, array $byParent): array
    {
        $teacher = $this->showAllProfiles ? null : $this->teacher();
        $nodes   = [];
        foreach ($byParent[$parentKey] ?? [] as $category) {
            if ($teacher !== null && !$this->categoryHasRelevantActivity($category, $teacher)) {
                continue;
            }
            $nodes[] = [
                'category' => $category,
                'children' => $this->buildCategoryTreeNodes($category->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }

    // ── Activities in the current category ───────────────────────────────────

    /** @return Activity[] */
    public function getVisibleActivities(): array
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return [];
        }

        $all = $this->activities->findByCategory($category, $this->canEdit());
        if ($this->showAllProfiles) {
            return $all;
        }

        $teacher = $this->teacher();

        return array_values(array_filter($all, fn (Activity $a): bool => $this->access->isActivityRelevantToTeacher($teacher, $a)));
    }
}
