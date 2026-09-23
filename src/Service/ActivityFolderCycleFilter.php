<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Folder;
use App\Repository\DocumentRepository;

/**
 * Which academic year's submissions an activity's folder shows (and downloads as a ZIP): by
 * default only the current occurrence's, or one earlier academic year's, or all of them. Shared
 * by SectionBrowserComponent (the selector on screen) and FolderZipExporter (so the ZIP always
 * holds what is on screen). A folder that backs no activity is never filtered.
 *
 * A selection is a plain string, the same on screen and in the ZIP link's query string: "" for
 * the current academic year, self::ALL for every year, or a cycle key ("2025" for 2025-2026).
 */
final class ActivityFolderCycleFilter
{
    public const string ALL = 'todos';

    public function __construct(
        private readonly ActivityDeadlineChecker $deadline,
        private readonly DocumentRepository $documents,
    ) {}

    /**
     * The cycles a selector should offer for $folder, most recent first: always the current one
     * (the default view, even while it has no submissions yet), plus every other academic year
     * that actually has submissions — never an earlier year with none. Empty when there's nothing
     * to choose: no activity behind the folder, or no submissions from any year but the current one.
     *
     * @return list<int>
     */
    public function options(Folder $folder): array
    {
        $current = $this->currentCycle($folder);
        if ($current === null) {
            return [];
        }

        $cycles   = $this->documents->findActivityCycleYearsInFolder($folder);
        $cycles[] = $current;
        $cycles   = array_values(array_unique($cycles));
        rsort($cycles);

        return count($cycles) > 1 ? $cycles : [];
    }

    /** The current occurrence's cycle key, or null for a folder that backs no activity. */
    public function currentCycle(Folder $folder): ?int
    {
        $activity = $folder->getActivity();

        return $activity === null ? null : $this->deadline->currentCycleKey($activity);
    }

    /** The single cycle $selection narrows $folder down to; null when it isn't narrowed at all (every year, or no activity). */
    public function selectedCycle(Folder $folder, string $selection): ?int
    {
        $current = $this->currentCycle($folder);
        if ($current === null || $selection === self::ALL) {
            return null;
        }

        return preg_match('/^\d{4}$/', $selection) === 1 ? (int) $selection : $current;
    }

    /**
     * $documents (all of $folder's), narrowed down to $selection. When every year is shown, they
     * come most recent academic year first, keeping their own relative order within each year.
     *
     * @param list<Document> $documents
     *
     * @return list<Document>
     */
    public function filter(Folder $folder, array $documents, string $selection): array
    {
        $current = $this->currentCycle($folder);
        if ($current === null) {
            return $documents;
        }

        // A submission that somehow escaped stamping (see DocumentActivityCycleListener) counts as this year's.
        $cycleOf  = static fn (Document $d): int => $d->getActivityCycleYear() ?? $current;
        $selected = $this->selectedCycle($folder, $selection);

        if ($selected === null) {
            usort($documents, static fn (Document $a, Document $b): int => $cycleOf($b) <=> $cycleOf($a));

            return $documents;
        }

        return array_values(array_filter($documents, static fn (Document $d): bool => $cycleOf($d) === $selected));
    }
}
