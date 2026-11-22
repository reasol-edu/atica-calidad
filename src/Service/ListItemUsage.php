<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\DocumentRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;

/**
 * Which list items of a centre are in use, for the places that decide what can be removed (deleting a branch, importing a list):
 * ONE query per kind of use for the whole centre, tested in memory per item, instead of one query per item and kind.
 */
final class ListItemUsage
{
    public function __construct(
        private readonly SpecificProfileRepository $profiles,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly DocumentRepository $documents,
    ) {}

    /** @return array<string, true> items a profile takes its list from */
    public function profileLists(EducationalCentre $centre): array
    {
        return $this->profiles->findListItemIdsInUse($centre);
    }

    /**
     * Items that block a deletion: a profile takes its list from them, or a teacher is assigned through them.
     *
     * @return array<string, true>
     */
    public function blocking(EducationalCentre $centre): array
    {
        return $this->profiles->findListItemIdsInUse($centre) + $this->assignments->findAssignedListItemIds($centre);
    }

    /**
     * Items that cannot be deleted (only deactivated) by an import: the blocking ones and the ones documents were uploaded under.
     *
     * @return array<string, true>
     */
    public function keptByImports(EducationalCentre $centre): array
    {
        return $this->blocking($centre) + $this->documents->findUploadListItemIds($centre);
    }
}
