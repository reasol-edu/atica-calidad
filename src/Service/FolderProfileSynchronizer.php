<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Folder;
use App\Entity\FolderResponsibleProfile;
use App\Entity\FolderReviewProfile;
use App\Entity\FolderUploadProfile;
use App\Entity\FolderVisibilityProfile;
use App\Model\ProfileAssignmentRow;

/**
 * Reads and rewrites a folder's four profile lists (responsible, upload, visibility, review) from
 * the "profile key" arrays the pickers work with (ProfileAssignmentRow::key()). Shared by the
 * folder settings panel of the document tree and the activity form, which edits its folder's
 * profiles in place — one place for the rule "keys not offered are ignored, never a 500".
 */
final class FolderProfileSynchronizer
{
    public const string RESPONSIBLE = 'responsible';
    public const string UPLOAD      = 'upload';
    public const string VISIBILITY  = 'visibility';
    public const string REVIEW      = 'review';

    /**
     * @param iterable<FolderResponsibleProfile|FolderUploadProfile|FolderVisibilityProfile|FolderReviewProfile> $restrictions
     *
     * @return string[]
     */
    public function keysFor(iterable $restrictions): array
    {
        $keys = [];
        foreach ($restrictions as $restriction) {
            $keys[] = ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem());
        }

        return $keys;
    }

    /**
     * Makes the folder's list of $kind hold exactly $keys: restrictions whose key isn't there are
     * removed, keys with a matching row (in $rowsByKey) that the folder lacks are added, and keys
     * without a row (stale or tampered) are dropped.
     *
     * @param self::RESPONSIBLE|self::UPLOAD|self::VISIBILITY|self::REVIEW $kind
     * @param string[]                                                     $keys
     * @param array<string, ProfileAssignmentRow>                          $rowsByKey
     */
    public function sync(Folder $folder, string $kind, array $keys, array $rowsByKey): void
    {
        $current = match ($kind) {
            self::RESPONSIBLE => $folder->getResponsibleProfiles(),
            self::UPLOAD      => $folder->getUploadProfiles(),
            self::VISIBILITY  => $folder->getVisibilityProfiles(),
            self::REVIEW      => $folder->getReviewProfiles(),
        };
        foreach (iterator_to_array($current) as $restriction) {
            if (!\in_array(ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem()), $keys, true)) {
                match (true) {
                    $restriction instanceof FolderResponsibleProfile => $folder->removeResponsibleProfile($restriction),
                    $restriction instanceof FolderUploadProfile      => $folder->removeUploadProfile($restriction),
                    $restriction instanceof FolderVisibilityProfile  => $folder->removeVisibilityProfile($restriction),
                    $restriction instanceof FolderReviewProfile      => $folder->removeReviewProfile($restriction),
                };
            }
        }
        foreach ($keys as $key) {
            $row = $rowsByKey[$key] ?? null;
            if ($row === null) {
                continue;
            }
            // Each add*() already ignores a profile the folder holds.
            match ($kind) {
                self::RESPONSIBLE => $folder->addResponsibleProfile($row->profile, $row->listItem),
                self::UPLOAD      => $folder->addUploadProfile($row->profile, $row->listItem),
                self::VISIBILITY  => $folder->addVisibilityProfile($row->profile, $row->listItem),
                self::REVIEW      => $folder->addReviewProfile($row->profile, $row->listItem),
            };
        }
    }

    /**
     * Replaces all four lists at once.
     *
     * @param string[]                            $responsible
     * @param string[]                            $upload
     * @param string[]                            $visibility
     * @param string[]                            $review
     * @param array<string, ProfileAssignmentRow> $rowsByKey
     */
    public function syncAll(Folder $folder, array $responsible, array $upload, array $visibility, array $review, array $rowsByKey): void
    {
        $this->sync($folder, self::RESPONSIBLE, $responsible, $rowsByKey);
        $this->sync($folder, self::UPLOAD, $upload, $rowsByKey);
        $this->sync($folder, self::VISIBILITY, $visibility, $rowsByKey);
        $this->sync($folder, self::REVIEW, $review, $rowsByKey);
    }
}
