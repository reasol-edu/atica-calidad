<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

/**
 * Search of the activity browser: categories, activities and submissions.
 */
trait ActivitySearch
{
    // ── Search ───────────────────────────────────────────────────────────────

    /** @return array<int, array{category: ActivityCategory, path: string, direct: bool}> */
    public function getCategorySearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->categories->searchByCentre($this->centre, $query) as $category) {
            $results[] = ['category' => $category, 'path' => $this->categorySearchPath($category), 'direct' => $this->categoryHasRelevantActivity($category, $teacher)];
        }

        return $results;
    }

    /** @return array<int, array{activity: Activity, path: string, direct: bool}> */
    public function getActivitySearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->activities->searchByCentre($this->centre, $query, includeHidden: $this->canEdit()) as $activity) {
            $folder = $activity->getFolder();
            if ($folder !== null && !$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $results[] = ['activity' => $activity, 'path' => $this->categoryTrail($activity->getCategory()), 'direct' => $this->access->isActivityRelevantToTeacher($teacher, $activity)];
        }

        return $results;
    }

    /** @return array<int, array{document: Document, activity: Activity, path: string, direct: bool}> */
    public function getSubmissionSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->documents->searchActivitySubmissionsByCentre($this->centre, $query) as $document) {
            $folder   = $document->getFolder();
            $activity = $folder->getActivity();
            if ($activity === null || ($activity->isHidden() && !$this->canEdit()) || !$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $results[] = [
                'document' => $document,
                'activity' => $activity,
                'path'     => $this->categoryTrail($activity->getCategory()) . ' › ' . $activity->getTitle(),
                'direct'   => $this->access->isActivityRelevantToTeacher($teacher, $activity),
            ];
        }

        return $results;
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function openCategorySearchResult(#[LiveArg] string $id): void
    {
        $category = $this->categories->findByIdAndCentre($id, $this->centre);
        if ($category === null) {
            return;
        }
        if (!$this->categoryHasRelevantActivity($category, $this->teacher())) {
            $this->showAllProfiles = true;
        }
        $this->currentCategoryId = $id;
        $this->resetTransientState();
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function openActivitySearchResult(#[LiveArg] string $id): void
    {
        $activity = $this->findActivity($id);
        if ($activity === null) {
            return;
        }
        if (!$this->access->isActivityRelevantToTeacher($this->teacher(), $activity)) {
            $this->showAllProfiles = true;
        }
        $this->currentCategoryId = $activity->getCategory()->getId()->toRfc4122();
        $this->resetTransientState();
        $this->searchQuery = '';
        $this->landOnActivity($id);
    }

    #[LiveAction]
    public function openSubmissionSearchResult(#[LiveArg] string $documentId): void
    {
        $document = $this->findDocument($documentId);
        $activity = $document?->getFolder()->getActivity();
        if ($document === null || $activity === null) {
            return;
        }
        if (!$this->access->isActivityRelevantToTeacher($this->teacher(), $activity)) {
            $this->showAllProfiles = true;
        }
        $this->currentCategoryId      = $activity->getCategory()->getId()->toRfc4122();
        $this->highlightedDocumentId  = $documentId;
        $this->resetTransientState();
        $this->searchQuery = '';
        // Unfolded with every delivery listed: the row of the one found scrolls itself into view.
        $this->landOnActivity($activity->getId()->toRfc4122());
        if (!in_array($activity->getId()->toRfc4122(), $this->expandedAllSubmissions, true)) {
            $this->expandedAllSubmissions[] = $activity->getId()->toRfc4122();
        }
    }

    private function categoryTrail(ActivityCategory $category): string
    {
        $trail = [];
        for ($c = $category; $c !== null; $c = $c->getParent()) {
            array_unshift($trail, $c->getName());
        }

        return implode(' › ', $trail);
    }

    private function categorySearchPath(ActivityCategory $category): string
    {
        $parent = $category->getParent();
        if ($parent === null) {
            return $this->translator->trans('breadcrumb.root', [], 'activity_content');
        }

        return $this->categoryTrail($parent);
    }
}
