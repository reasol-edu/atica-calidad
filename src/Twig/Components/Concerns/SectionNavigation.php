<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\DocumentSection;
use App\Service\DocumentTreeAccessChecker;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Section navigation of SectionBrowserComponent: current level, breadcrumb, section tree.
 */
trait SectionNavigation
{
    /** '' means the root level. */
    #[LiveProp(writable: true)]
    public string $currentSectionId = '';

    /** @var DocumentSection[]|null memoised per render — see getSectionTree() */
    private ?array $allSectionsCache = null;

    // ── Section navigation ───────────────────────────────────────────────────

    public function getCurrentSection(): ?DocumentSection
    {
        if ($this->currentSectionId === '') {
            return null;
        }

        return $this->sections->findByIdAndCentre($this->currentSectionId, $this->centre);
    }

    /** @return DocumentSection[] */
    public function getVisibleSections(): array
    {
        $parent   = $this->getCurrentSection();
        $siblings = $parent === null
            ? $this->sections->findRootsByCentre($this->centre)
            : $this->sections->findChildrenByParent($parent);

        $teacher = $this->teacher();

        return array_values(array_filter(
            $siblings,
            fn (DocumentSection $s): bool => $this->access->canViewSection($teacher, $s)
        ));
    }

    /** @return DocumentSection[] root-first path of ancestors down to (and including) the current section */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentSection(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    /**
     * The whole tree this teacher can browse, nested and permission-filtered — for the sidebar
     * shown alongside the breadcrumb/cards browsing on wide screens (see the template). A section
     * this teacher can't view is pruned along with its entire subtree: there's no way to reach a
     * hidden section's children by browsing either, restrictions never cascading notwithstanding
     * (DocumentTreeAccessChecker) — a subsection of a hidden one simply has no door left open to it.
     *
     * @return array<int, array{section: DocumentSection, children: array<mixed>}>
     */
    public function getSectionTree(): array
    {
        $byParent = [];
        foreach ($this->allSectionsForTree() as $section) {
            $key              = $section->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $section;
        }

        return $this->buildVisibleTreeNodes('', $byParent);
    }

    /** @return DocumentSection[] every section of the centre, loaded once per render (profile restrictions eager-loaded, for canViewSection()) */
    private function allSectionsForTree(): array
    {
        return $this->allSectionsCache ??= $this->sections->findAllByCentre($this->centre);
    }

    /**
     * @param array<string, DocumentSection[]> $byParent
     *
     * @return array<int, array{section: DocumentSection, children: array<mixed>}>
     */
    private function buildVisibleTreeNodes(string $parentKey, array $byParent): array
    {
        $teacher = $this->teacher();
        $nodes   = [];
        foreach ($byParent[$parentKey] ?? [] as $section) {
            if (!$this->access->canViewSection($teacher, $section)) {
                continue;
            }
            $nodes[] = [
                'section'  => $section,
                'children' => $this->buildVisibleTreeNodes($section->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }

    #[LiveAction]
    public function openLevel(#[LiveArg] string $id): void
    {
        $this->currentSectionId = $id;
        $this->resetTransientState();
        $this->dispatchLocation();
    }

    private function resetTransientState(): void
    {
        $this->expandedFolderIds         = [];
        $this->folderCycles              = [];
        $this->addingFolder              = false;
        $this->renamingFolderId          = '';
        $this->confirmingDeleteFolderId  = '';
        $this->folderSettingsPanelId     = '';
        $this->confirmingObsoleteFolderId = '';
        $this->renamingDocumentId        = '';
        $this->confirmingDeleteDocumentId = '';
        $this->movingDocumentId          = '';
        $this->revisionPanelDocumentId   = '';
        $this->highlightedDocumentId     = '';
        $this->editingRevisionId         = '';
        $this->confirmingDeleteRevisionId = '';
        $this->localSearchQuery          = '';
        $this->errors                    = [];
    }
}
