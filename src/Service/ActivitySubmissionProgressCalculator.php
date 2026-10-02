<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Document;
use App\Model\ActivitySubmissionProgress;
use App\Model\ActivitySubmissionSlot;
use App\Repository\DocumentRepository;
use Symfony\Component\Clock\ClockInterface;

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
        private readonly ActivitySubmissionSlotBuilder $slotBuilder,
        private readonly ClockInterface $clock,
    ) {}

    /** Null for an activity without a folder: there's nothing to submit. */
    public function forActivity(Activity $activity): ?ActivitySubmissionProgress
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return null;
        }

        $slots = $this->completion->getAllSlots($activity);

        // One query per distinct cycle: a list element with its own deadline override can belong to
        // a different academic-year occurrence than the activity's own (see ActivityDeadlineChecker).
        /** @var array<int, array<string, list<Document>>> $byCycle */
        $byCycle = [];
        foreach ($slots as $slot) {
            $cycle = $this->slotBuilder->cycleKeyFor($activity, $slot);
            if (isset($byCycle[$cycle])) {
                continue;
            }
            $byCycle[$cycle] = [];
            foreach ($this->documents->findSubmissionsWithRevisions($folder, $cycle) as $document) {
                $byCycle[$cycle][$this->key($document->getUploadProfile()?->getId()->toRfc4122(), $document->getUploadListItem()?->getId()->toRfc4122(), $document->getName())][] = $document;
            }
        }

        $now   = $this->clock->now();
        $total = $delivered = $accepted = $inReview = $rejected = $overdue = 0;
        foreach ($slots as $slot) {
            ++$total;
            $document = $this->match($slot, $byCycle[$this->slotBuilder->cycleKeyFor($activity, $slot)] ?? []);
            $pending  = $document === null || (!$document->isPendingApproval() && $document->getActiveRevision() === null);
            if ($pending && $now > $this->deadline->currentCycleEndDate($activity, $slot->nameListItem)) {
                ++$overdue;
            }
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

        return new ActivitySubmissionProgress($total, $delivered, $accepted, $inReview, $rejected, $overdue);
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
