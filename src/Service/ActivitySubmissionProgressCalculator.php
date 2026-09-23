<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Document;
use App\Model\ActivitySubmissionProgress;
use App\Model\ActivitySubmissionSlot;
use App\Repository\DocumentRepository;

/**
 * An activity's overall submission progress (see ActivitySubmissionProgress) for its current
 * occurrence. Meant for a whole page of activity cards at once, so it doesn't resolve slot by
 * slot (ActivitySubmissionSlotBuilder::resolveSlot(), one query each): it loads the folder's
 * submissions for this academic year in one query and pairs them with the slots in memory, with
 * the very same matching rule — upload profile/subprofile and name, plus the first uploader for a
 * slot assigned to one specific teacher.
 */
final class ActivitySubmissionProgressCalculator
{
    public function __construct(
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly DocumentRepository $documents,
    ) {}

    /** Null for an activity without a folder: there's nothing to submit. */
    public function forActivity(Activity $activity): ?ActivitySubmissionProgress
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return null;
        }

        /** @var array<string, list<Document>> $byKey */
        $byKey = [];
        foreach ($this->documents->findSubmissionsWithRevisions($folder, $this->deadline->currentCycleKey($activity)) as $document) {
            $byKey[$this->key($document->getUploadProfile()?->getId()->toRfc4122(), $document->getUploadListItem()?->getId()->toRfc4122(), $document->getName())][] = $document;
        }

        $total = $delivered = $accepted = $inReview = $rejected = 0;
        foreach ($this->completion->getAllSlots($activity) as $slot) {
            ++$total;
            $document = $this->match($slot, $byKey);
            if ($document === null) {
                continue;
            }
            ++$delivered;
            match (true) {
                $document->isPendingApproval()          => ++$inReview,
                $document->getActiveRevision() !== null => ++$accepted,
                default                                 => ++$rejected,
            };
        }

        return new ActivitySubmissionProgress($total, $delivered, $accepted, $inReview, $rejected);
    }

    /** @param array<string, list<Document>> $byKey */
    private function match(ActivitySubmissionSlot $slot, array $byKey): ?Document
    {
        $candidates = $byKey[$this->key($slot->profile->getId()->toRfc4122(), $slot->listItem?->getId()->toRfc4122(), $slot->displayName)] ?? [];
        if ($slot->teacher === null) {
            return $candidates[0] ?? null;
        }

        foreach ($candidates as $document) {
            if ($document->getFirstRevision()?->getUploadedBy() === $slot->teacher) {
                return $document;
            }
        }

        return null;
    }

    private function key(?string $profileId, ?string $listItemId, string $name): string
    {
        return ($profileId ?? '') . '|' . ($listItemId ?? '') . '|' . $name;
    }
}
