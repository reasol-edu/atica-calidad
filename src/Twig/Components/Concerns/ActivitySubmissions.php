<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\ActivityChange;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionProgress;
use App\Model\ActivitySubmissionSlot;
use App\Model\ProfileAssignmentRow;
use App\Service\ActivityDeadlineChecker;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Submission slots of an activity: who has to deliver what, progress, stats, history and pending reviews.
 */
trait ActivitySubmissions
{
    // ── Per-activity display toggles ─────────────────────────────────────────
    /**
     * Activity ids whose card is unfolded. The cards of a category come folded (title, deadline, status, progress): an activity with
     * many deliveries made the page endless. A search result, a link to an activity or to one of its deliveries unfolds it.
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $expandedActivities = [];

    /** The activity a search or a link landed on: its card scrolls into view (unless a delivery row of it does). */
    #[LiveProp]
    public string $landedActivityId = '';

    /** @var string[] activity ids whose "todas las entregas" section is expanded. */
    #[LiveProp(writable: true)]
    public array $expandedAllSubmissions = [];

    /** @var string[] activity ids whose stats panel is shown. */
    #[LiveProp(writable: true)]
    public array $statsShown = [];

    /** @var string[] activity ids whose history panel is shown. */
    #[LiveProp]
    public array $historyShown = [];


    // ── Submission slots ──────────────────────────────────────────────────────

    /** @return ActivitySubmissionSlot[] every expected submission of $activity. */
    public function getAllSlots(Activity $activity): array
    {
        return $this->completion->getAllSlots($activity);
    }

    /** @return ActivitySubmissionSlot[] the slots the current teacher is personally responsible for. */
    public function getMySlots(Activity $activity): array
    {
        return $this->completion->getMySlots($this->teacher(), $activity);
    }

    /** @return ActivitySubmissionSlot[] the slots of the profiles (and teacher, for an Individual scope) the current teacher really holds — managing the folder does not add any. */
    public function getOwnedSlots(Activity $activity): array
    {
        return $this->completion->getMyOwnedSlots($this->teacher(), $activity);
    }

    /** @return ActivitySubmissionSlot[] the slots the current teacher may act on without holding them: the rest of the folder's, for whoever manages it. */
    public function getManagedSlots(Activity $activity): array
    {
        $ownedKeys = array_map(static fn (ActivitySubmissionSlot $s): string => $s->key(), $this->getOwnedSlots($activity));

        return array_values(array_filter(
            $this->getMySlots($activity),
            static fn (ActivitySubmissionSlot $s): bool => !in_array($s->key(), $ownedKeys, true),
        ));
    }

    /** @return array{pending: int, toReview: int} how many of the managed slots still have no document, and how many have one waiting for review */
    public function getManagedSummary(Activity $activity): array
    {
        $pending = $toReview = 0;
        foreach ($this->getManagedSlots($activity) as $slot) {
            $document = $this->resolveSlot($activity, $slot);
            if ($document === null) {
                ++$pending;
            } elseif ($document->getPendingRevision() !== null) {
                ++$toReview;
            }
        }

        return ['pending' => $pending, 'toReview' => $toReview];
    }

    /** The key of the slot the page was asked to land on ('' for none) — "next" resolved to the first of the teacher's own still to do. */
    public function getSlotToHighlight(Activity $activity): string
    {
        if ($this->highlightedSlotKey !== 'next') {
            return $this->highlightedSlotKey;
        }

        foreach ($this->getMySlots($activity) as $slot) {
            $document = $this->resolveSlot($activity, $slot);
            if ($document === null || ($document->getActiveRevision() === null && $document->getPendingRevision() === null)) {
                return $slot->key();
            }
        }

        return '';
    }

    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot): ?Document
    {
        return $this->completion->resolveSlot($activity, $slot);
    }

    /**
     * Whether the current teacher has at least one of their own slots still without a document —
     * i.e. "Mis entregas" actually offers a dropzone right now, as opposed to every one of their
     * slots already being filled. Used to decide whether the folder's format restriction (if any)
     * is even relevant to show them.
     */
    public function hasAnOpenSubmissionSlot(Activity $activity): bool
    {
        foreach ($this->getMySlots($activity) as $slot) {
            if ($this->resolveSlot($activity, $slot) === null) {
                return true;
            }
        }

        return false;
    }

    /** @return ActivitySubmissionSlot[] every slot NOT already covered by getMySlots() — "the rest of the profiles'" deliveries. */
    public function getOtherSlots(Activity $activity): array
    {
        $mineKeys = array_map(static fn (ActivitySubmissionSlot $s): string => $s->key(), $this->getMySlots($activity));

        return array_values(array_filter(
            $this->getAllSlots($activity),
            static fn (ActivitySubmissionSlot $s): bool => !in_array($s->key(), $mineKeys, true),
        ));
    }

    #[LiveAction]
    public function toggleActivity(#[LiveArg] string $activityId): void
    {
        $this->landedActivityId = '';
        if (in_array($activityId, $this->expandedActivities, true)) {
            $this->expandedActivities = array_values(array_diff($this->expandedActivities, [$activityId]));
        } else {
            $this->expandedActivities[] = $activityId;
        }
    }

    /** Unfolds $activityId alone and marks it as the one landed on (scrolled to). */
    private function landOnActivity(string $activityId): void
    {
        $this->expandedActivities = [$activityId];
        $this->landedActivityId   = $activityId;
    }

    #[LiveAction]
    public function toggleAllSubmissions(#[LiveArg] string $activityId): void
    {
        if (in_array($activityId, $this->expandedAllSubmissions, true)) {
            $this->expandedAllSubmissions = array_values(array_diff($this->expandedAllSubmissions, [$activityId]));
        } else {
            $this->expandedAllSubmissions[] = $activityId;
        }
    }

    /** Opens or closes an activity's history panel (only whoever can edit activities may). */
    #[LiveAction]
    public function toggleHistory(#[LiveArg] string $activityId): void
    {
        $this->requireEditPermission();
        if (in_array($activityId, $this->historyShown, true)) {
            $this->historyShown = array_values(array_diff($this->historyShown, [$activityId]));
        } else {
            $this->historyShown[] = $activityId;
        }
    }

    /** @return list<ActivityChange> the activity's history, newest first — read only while its panel is open */
    public function getActivityHistory(Activity $activity): array
    {
        return $this->canEdit() ? $this->activityChanges->findByActivity($activity) : [];
    }

    #[LiveAction]
    public function toggleStats(#[LiveArg] string $activityId): void
    {
        if (in_array($activityId, $this->statsShown, true)) {
            $this->statsShown = array_values(array_diff($this->statsShown, [$activityId]));
        } else {
            $this->statsShown[] = $activityId;
        }
    }

    /**
     * Delivered/accepted counts grouped by upload profile/subprofile (ByProfile scope) or by
     * teacher (Individual scope) — never per individual named submission, matching the request's
     * own "4/8 (50%)" example, which counts rows of a *person or profile*, not of a leaf name.
     *
     * @return array{groups: list<array{label: string, total: int, delivered: int, accepted: int}>, needsReview: bool}
     */
    public function getStats(Activity $activity): array
    {
        $needsReview = $activity->getFolder()?->requiresReview() ?? false;
        $groups      = [];

        foreach ($this->getAllSlots($activity) as $slot) {
            if ($activity->getSubmissionScope() === ActivitySubmissionScope::Individual) {
                $key   = $slot->teacher?->getId()->toRfc4122() ?? '';
                $label = $slot->teacher === null ? '' : $slot->teacher->getName()->getLastName() . ', ' . $slot->teacher->getName()->getFirstName();
            } else {
                $key   = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
                $label = $slot->profile->getName() . ($slot->listItem !== null ? ' ' . $slot->listItem->getName() : '');
            }

            $groups[$key] ??= ['label' => $label, 'total' => 0, 'delivered' => 0, 'accepted' => 0];
            ++$groups[$key]['total'];

            $document = $this->resolveSlot($activity, $slot);
            if ($document === null) {
                continue;
            }
            ++$groups[$key]['delivered'];
            if ($document->getActiveRevision() !== null) {
                ++$groups[$key]['accepted'];
            }
        }

        return ['groups' => array_values($groups), 'needsReview' => $needsReview];
    }

    /**
     * The activity's overall submission progress (every slot, this academic year) — only for
     * whoever manages or reviews its folder, who need the whole picture at a glance on the card
     * itself; null for everyone else, and for an activity without a folder.
     */
    public function getSubmissionProgress(Activity $activity): ?ActivitySubmissionProgress
    {
        $folder = $activity->getFolder();
        if ($folder === null || !($this->canManageFolder($folder) || $this->canReviewFolder($folder))) {
            return null;
        }

        return $this->progress->forActivity($activity);
    }

    /**
     * Per-teacher completion breakdown of a manual (folder-less) activity, this academic year —
     * the no-folder equivalent of getSubmissionProgress(), for whoever manages the activity (see
     * canManageActivity()); null for a folder-backed activity, for anyone who doesn't manage it, or
     * when the centre has no active academic year.
     *
     * @return ?list<array{teacher: Teacher, completed: bool}>
     */
    public function getManualCompletionStats(Activity $activity): ?array
    {
        if ($activity->requiresSubmissions() || !$this->canManageActivity($activity)) {
            return null;
        }

        $year = $this->centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->completion->manualCompletionStats($activity, $this->teachers->findByAcademicYearOrderedByName($year));
    }

    /**
     * The activity's submissions awaiting review, for its bulk review box — only for whoever
     * reviews its folder; empty for everyone else. Split between the current occurrence's and
     * those left from earlier ones (by their document's cycle key, see
     * DocumentActivityCycleListener), so last year's are never approved or rejected along with
     * this year's by mistake; the latter carry the academic year they belong to.
     *
     * @return array{current: list<DocumentRevision>, older: list<array{revision: DocumentRevision, year: ?string}>}
     */
    public function getPendingReviews(Activity $activity): array
    {
        $folder = $activity->getFolder();
        if ($folder === null || !$this->canReviewFolder($folder)) {
            return ['current' => [], 'older' => []];
        }

        $cycle   = $this->deadline->currentCycleKey($activity);
        $current = [];
        $older   = [];
        foreach ($this->revisions->findPendingReviewByFolder($folder) as $revision) {
            $year = $revision->getDocument()->getActivityCycleYear();
            if ($year === $cycle) {
                $current[] = $revision;
            } else {
                $older[] = ['revision' => $revision, 'year' => $year === null ? null : ActivityDeadlineChecker::academicYearLabel($year)];
            }
        }

        return ['current' => $current, 'older' => $older];
    }

    /** "Recordar a pendientes": same rule as ActivityController::canRemind(). */
    public function canRemindPending(Activity $activity): bool
    {
        $folder = $activity->getFolder();

        return $this->canEdit() || ($folder !== null && ($this->canManageFolder($folder) || $this->canReviewFolder($folder)));
    }

    /**
     * Delivered/accepted/rejected counts across just the current teacher's own slots (see
     * getOwnedSlots()) — the compact one-line summary shown next to "Mis entregas", as opposed to
     * getStats()'s full breakdown grouped across every slot in the activity. A slot counts as
     * rejected when it has a document with no pending and no active revision but at least one
     * rejected one — Document has no isRejected()/getLatestRevision() of its own, but a rejected
     * revision blocks neither a later pending upload nor a later approval, so "no pending, no
     * active, something was rejected" is enough without needing the true latest revision.
     *
     * @return array{total: int, delivered: int, accepted: int, rejected: int, needsReview: bool}
     */
    public function getMySubmissionStats(Activity $activity): array
    {
        $needsReview = $activity->getFolder()?->requiresReview() ?? false;
        $total       = $delivered = $accepted = $rejected = 0;

        foreach ($this->getOwnedSlots($activity) as $slot) {
            ++$total;

            $document = $this->resolveSlot($activity, $slot);
            if ($document === null) {
                continue;
            }
            ++$delivered;
            if ($document->getActiveRevision() !== null) {
                ++$accepted;
            } elseif ($document->getPendingRevision() === null
                && $document->getRevisions()->exists(static fn (int $i, DocumentRevision $r): bool => $r->isRejected())
            ) {
                ++$rejected;
            }
        }

        return ['total' => $total, 'delivered' => $delivered, 'accepted' => $accepted, 'rejected' => $rejected, 'needsReview' => $needsReview];
    }
}
