<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Model\ProfileAssignmentRow;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Builds the flat "profile or subprofile" row list for a centre: one row per profile with no list
 * association, plus one row per leaf descendant of every list-associated profile, each carrying
 * its currently assigned teachers. Shared between the "Asignar perfiles" screen and the
 * calendar's profile-restricted-event picker, so the flattening logic lives in one place.
 *
 * The three underlying repository reads are memoized per centre/list-item for the life of one
 * request — buildAllRows() alone used to re-run all three every time it was called, which a
 * caller looping over many activities/folders (each needing the same centre-wide catalog) ended
 * up doing dozens of times per render for identical results (see the "Mis actividades" tab).
 *
 * Caching Doctrine entities (not just scalars) on a normally-shared service is only safe if the
 * cache is cleared at the same point Doctrine's own identity map resets — otherwise a long-running
 * worker (or this app's own test suite, which deliberately keeps one kernel across several
 * requests to avoid reopening the SQLite connection — see ControllerTestCase) would compare a
 * cached, now-detached entity against a freshly-hydrated one and silently fail `===` checks
 * (confirmed the hard way: this exact bug broke folder-upload-profile matching before
 * ResetInterface was added here). implements ResetInterface — mirroring TenantContext — so
 * Symfony clears these caches via the same kernel.reset pass that resets the EntityManager,
 * instead of via ad hoc invalidate() calls that are easy to miss at some write site.
 * invalidate() still exists and is still called explicitly by
 * SpecificProfileAssignmentsComponent, whose LiveActions mutate assignments and read through this
 * builder again in the *same* request — reset() alone only runs between requests, not mid-request.
 */
final class ProfileAssignmentRowBuilder implements ResetInterface
{
    /** @var array<string, SpecificProfileAssignment[]> */
    private array $assignmentsCache = [];

    /** @var array<string, SpecificProfile[]> */
    private array $profilesCache = [];

    /** @var array<string, ListItem[]> */
    private array $leafDescendantsCache = [];

    public function __construct(
        private readonly SpecificProfileRepository $profiles,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileAssignmentRepository $assignments,
    ) {}

    /** Clears every memoized read — call after mutating a profile's assignments if this same instance is read again afterward in the same request. */
    public function invalidate(): void
    {
        $this->assignmentsCache     = [];
        $this->profilesCache        = [];
        $this->leafDescendantsCache = [];
    }

    /** Symfony calls this automatically between requests (kernel.reset) — see the class docblock. */
    public function reset(): void
    {
        $this->invalidate();
    }

    /** @return ProfileAssignmentRow[] */
    public function buildAllRows(EducationalCentre $centre): array
    {
        $teachersByKey = [];
        foreach ($this->assignmentsForCentre($centre) as $assignment) {
            $key                   = ProfileAssignmentRow::keyFor($assignment->getSpecificProfile(), $assignment->getListItem());
            $teachersByKey[$key][] = $assignment->getTeacher();
        }

        $rows = [];
        foreach ($this->profilesForCentre($centre) as $profile) {
            $listItem = $profile->getListItem();

            if ($listItem === null) {
                $key    = ProfileAssignmentRow::keyFor($profile, null);
                $rows[] = new ProfileAssignmentRow($profile, null, $profile->getName(), $profile->isActive(), $teachersByKey[$key] ?? []);

                continue;
            }

            foreach ($this->leafDescendantsOf($listItem) as $leaf) {
                $key    = ProfileAssignmentRow::keyFor($profile, $leaf);
                $rows[] = new ProfileAssignmentRow(
                    $profile,
                    $leaf,
                    $profile->getName() . ' ' . $leaf->getName(),
                    $profile->isActive() && $leaf->isActive(),
                    $teachersByKey[$key] ?? [],
                );
            }
        }

        return $rows;
    }

    /** @return SpecificProfileAssignment[] */
    private function assignmentsForCentre(EducationalCentre $centre): array
    {
        return $this->assignmentsCache[$centre->getId()->toRfc4122()] ??= $this->assignments->findAllForCentre($centre);
    }

    /** @return SpecificProfile[] */
    private function profilesForCentre(EducationalCentre $centre): array
    {
        return $this->profilesCache[$centre->getId()->toRfc4122()] ??= $this->profiles->findByCentre($centre);
    }

    /** @return ListItem[] */
    private function leafDescendantsOf(ListItem $listItem): array
    {
        return $this->leafDescendantsCache[$listItem->getId()->toRfc4122()] ??= $this->listItems->findLeafDescendants($listItem);
    }

    /** @return ProfileAssignmentRow[] only rows currently eligible for a new use (active profile/subprofile) */
    public function buildActiveRows(EducationalCentre $centre): array
    {
        return array_values(array_filter(
            $this->buildAllRows($centre),
            static fn (ProfileAssignmentRow $row): bool => $row->active,
        ));
    }

    /**
     * Like buildActiveRows(), but a list-associated profile also gets one extra row for itself
     * (no specific leaf) right before its per-subprofile rows — a "whole profile" pick meaning
     * every subprofile counts, for pickers where enumerating each one isn't required (e.g.
     * restricting a folder to a profile without pinning it to one particular subprofile). Rows are
     * grouped by profile, groups ordered alphabetically by profile name, so a picker listing them
     * reads as: generic option first, then its subprofiles in list order, then the next profile.
     *
     * @return ProfileAssignmentRow[]
     */
    public function buildActiveRowsWithWholeProfileOption(EducationalCentre $centre): array
    {
        $rowsByProfile = [];
        foreach ($this->buildActiveRows($centre) as $row) {
            $rowsByProfile[$row->profile->getId()->toRfc4122()][] = $row;
        }

        $profiles = array_values(array_filter(
            $this->profilesForCentre($centre),
            static fn (SpecificProfile $profile): bool => $profile->isActive(),
        ));
        usort($profiles, static fn (SpecificProfile $a, SpecificProfile $b): int => strcasecmp($a->getName(), $b->getName()));

        $rows = [];
        foreach ($profiles as $profile) {
            $key = $profile->getId()->toRfc4122();
            if ($profile->getListItem() !== null) {
                $rows[] = new ProfileAssignmentRow($profile, null, $profile->getName() . ' (todos)', true, []);
            }
            foreach ($rowsByProfile[$key] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
