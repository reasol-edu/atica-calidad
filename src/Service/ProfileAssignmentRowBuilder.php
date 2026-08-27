<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
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
}
