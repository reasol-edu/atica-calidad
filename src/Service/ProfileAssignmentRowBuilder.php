<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SpecificProfile;
use App\Model\ProfileAssignmentRow;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;

/**
 * Builds the flat "profile or subperfil" row list for a centre: one row per profile with no list
 * association, plus one row per leaf descendant of every list-associated profile, each carrying
 * its currently assigned teachers. Shared between the "Asignar perfiles" screen and the
 * calendar's profile-restricted-event picker, so the flattening logic lives in one place.
 */
final class ProfileAssignmentRowBuilder
{
    public function __construct(
        private readonly SpecificProfileRepository $profiles,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileAssignmentRepository $assignments,
    ) {}

    /** @return ProfileAssignmentRow[] */
    public function buildAllRows(EducationalCentre $centre): array
    {
        $teachersByKey = [];
        foreach ($this->assignments->findAllForCentre($centre) as $assignment) {
            $key                   = ProfileAssignmentRow::keyFor($assignment->getSpecificProfile(), $assignment->getListItem());
            $teachersByKey[$key][] = $assignment->getTeacher();
        }

        $rows = [];
        foreach ($this->profiles->findByCentre($centre) as $profile) {
            $listItem = $profile->getListItem();

            if ($listItem === null) {
                $key    = ProfileAssignmentRow::keyFor($profile, null);
                $rows[] = new ProfileAssignmentRow($profile, null, $profile->getName(), $profile->isActive(), $teachersByKey[$key] ?? []);

                continue;
            }

            foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
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

    /** @return ProfileAssignmentRow[] only rows currently eligible for a new use (active profile/subperfil) */
    public function buildActiveRows(EducationalCentre $centre): array
    {
        return array_values(array_filter(
            $this->buildAllRows($centre),
            static fn (ProfileAssignmentRow $row): bool => $row->active,
        ));
    }

    /**
     * Like buildActiveRows(), but a list-associated profile also gets one extra row for itself
     * (no specific leaf) right before its per-subperfil rows — a "whole profile" pick meaning
     * every subperfil counts, for pickers where enumerating each one isn't required (e.g.
     * restricting a folder to a profile without pinning it to one particular subperfil). Rows are
     * grouped by profile, groups ordered alphabetically by profile name, so a picker listing them
     * reads as: generic option first, then its subperfiles in list order, then the next profile.
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
            $this->profiles->findByCentre($centre),
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
