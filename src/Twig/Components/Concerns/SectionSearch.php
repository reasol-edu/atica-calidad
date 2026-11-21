<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Folder;
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivityFolderCycleFilter;
use App\Service\DocumentTreeAccessChecker;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Global and local search of the section browser.
 */
trait SectionSearch
{
    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    /** Filters the current section's folders' document lists — unlike searchQuery, never leaves the section. */
    #[LiveProp(writable: true)]
    public string $localSearchQuery = '';

    // ── Search ───────────────────────────────────────────────────────────────

    /**
     * Documents matching the current search query anywhere in the centre's tree, each paired with
     * its section-path/folder breadcrumb for display — access-filtered the same way section/folder
     * browsing is (DocumentTreeAccessChecker), so search never surfaces something the teacher
     * couldn't otherwise reach.
     *
     * @return list<array{document: Document, path: string}>
     */
    public function getSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->documents->searchByCentre($this->centre, $query) as $document) {
            if (!$this->access->canViewDocument($teacher, $document)) {
                continue;
            }
            $results[] = ['document' => $document, 'path' => $this->documentPath($document)];
        }

        return $results;
    }

    /**
     * Folders whose own name matches the current search query anywhere in the centre's tree, each
     * paired with its section-path breadcrumb — same access filtering as document search. Shown as
     * a visually distinct group from document results (see the "search.folders_heading" label) so
     * it's clear a hit is a folder, not a document.
     *
     * @return list<array{folder: Folder, path: string}>
     */
    public function getFolderSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->folders->searchByCentre($this->centre, $query) as $folder) {
            if (!$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $results[] = ['folder' => $folder, 'path' => $this->folderPath($folder)];
        }

        return $results;
    }

    /**
     * Sections whose own name matches the current search query anywhere in the centre's tree,
     * each paired with its ancestor breadcrumb — same access filtering as document/folder search.
     * Shown as its own group ("search.sections_heading") so it's clear a hit is a whole section.
     *
     * @return list<array{section: DocumentSection, path: string}>
     */
    public function getSectionSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->sections->searchByCentre($this->centre, $query) as $section) {
            if (!$this->access->canViewSection($teacher, $section)) {
                continue;
            }
            $results[] = ['section' => $section, 'path' => $this->sectionSearchPath($section)];
        }

        return $results;
    }

    private function sectionSearchPath(DocumentSection $section): string
    {
        $parent = $section->getParent();
        if ($parent === null) {
            return $this->translator->trans('breadcrumb.root', [], 'document_content');
        }

        return implode(' › ', $this->sectionTrail($parent));
    }

    /** @return list<string> root-first names of a section's ancestor trail, including the section itself */
    private function sectionTrail(DocumentSection $section): array
    {
        $trail = [];
        for ($s = $section; $s !== null; $s = $s->getParent()) {
            array_unshift($trail, $s->getName());
        }

        return $trail;
    }

    private function documentPath(Document $document): string
    {
        $folder  = $document->getFolder();
        $trail   = $this->sectionTrail($folder->getDocumentSection());
        $trail[] = $folder->getName();

        return implode(' › ', $trail);
    }

    private function folderPath(Folder $folder): string
    {
        return implode(' › ', $this->sectionTrail($folder->getDocumentSection()));
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function clearLocalSearch(): void
    {
        $this->localSearchQuery = '';
    }

    /** Jumps straight to a search result: opens its section, expands its folder, and briefly highlights the document — never forces its revision panel open. */
    #[LiveAction]
    public function openSearchResult(#[LiveArg] string $documentId): void
    {
        $teacher  = $this->teacher();
        $document = $this->documents->findById($documentId);
        if ($document === null || $document->getFolder()->getEducationalCentre() !== $this->centre) {
            throw $this->createNotFoundException();
        }
        if (!$this->access->canViewDocument($teacher, $document)) {
            throw $this->createAccessDeniedException();
        }

        $folder = $document->getFolder();
        $this->currentSectionId = $folder->getDocumentSection()->getId()->toRfc4122();
        $this->resetTransientState();
        $this->expandedFolderIds      = [$folder->getId()->toRfc4122()];
        $this->highlightedDocumentId  = $documentId;
        $this->searchQuery            = '';
        $this->showCycleOf($document);
        $this->dispatchLocation();
    }

    /**
     * Academic years an activity folder's selector offers (most recent first), each with its
     * selection value; empty when there's nothing to choose between.
     *
     * @return list<array{value: string, label: string, current: bool}>
     */
    public function getFolderCycleOptions(Folder $folder): array
    {
        $current = $this->cycleFilter->currentCycle($folder);

        return array_map(
            static fn (int $cycle): array => [
                'value'   => $cycle === $current ? '' : (string) $cycle,
                'label'   => ActivityDeadlineChecker::academicYearLabel($cycle),
                'current' => $cycle === $current,
            ],
            $this->cycleFilter->options($folder),
        );
    }

    /** The ZIP link's selection: the same as on screen, but always explicit (never "") so the download can't drift if the academic year rolls over meanwhile. */
    public function getFolderZipCycle(Folder $folder): string
    {
        $selected = $this->cycleFilter->selectedCycle($folder, $this->folderCycles[$folder->getId()->toRfc4122()] ?? '');
        if ($selected !== null) {
            return (string) $selected;
        }

        return $this->cycleFilter->currentCycle($folder) === null ? '' : ActivityFolderCycleFilter::ALL;
    }

    /** Switches the selector to $document's own academic year when it isn't the current one, so jumping to it never lands on a list that hides it. */
    private function showCycleOf(Document $document): void
    {
        $folder  = $document->getFolder();
        $cycle   = $document->getActivityCycleYear();
        $current = $this->cycleFilter->currentCycle($folder);
        if ($cycle !== null && $current !== null && $cycle !== $current) {
            $this->folderCycles[$folder->getId()->toRfc4122()] = (string) $cycle;
        }
    }

    /** Jumps straight to a folder search result: opens its section and expands it. */
    #[LiveAction]
    public function openFolderSearchResult(#[LiveArg] string $folderId): void
    {
        $teacher = $this->teacher();
        $folder  = $this->folders->findById($folderId);
        if ($folder === null || $folder->getEducationalCentre() !== $this->centre) {
            throw $this->createNotFoundException();
        }
        if (!$this->access->canViewFolder($teacher, $folder)) {
            throw $this->createAccessDeniedException();
        }

        $this->currentSectionId  = $folder->getDocumentSection()->getId()->toRfc4122();
        $this->resetTransientState();
        $this->expandedFolderIds = [$folder->getId()->toRfc4122()];
        $this->searchQuery       = '';
        $this->dispatchLocation();
    }

    /** Jumps straight to a section search result: navigates to it, same as clicking through the breadcrumb. */
    #[LiveAction]
    public function openSectionSearchResult(#[LiveArg] string $sectionId): void
    {
        $teacher = $this->teacher();
        $section = $this->sections->findByIdAndCentre($sectionId, $this->centre);
        if ($section === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->access->canViewSection($teacher, $section)) {
            throw $this->createAccessDeniedException();
        }

        $this->currentSectionId = $sectionId;
        $this->resetTransientState();
        $this->searchQuery = '';
        $this->dispatchLocation();
    }
}
