<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityObligationStatus;
use App\Model\ActivityWindow;
use App\Repository\ActivityRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Every activity obligation a teacher owns, and where each one stands (ActivityObligationStatus)
 * — the one place that decides it, shared by the dashboard, "Mis actividades", the "Ver" cards,
 * the notification bell and the reminder emails, so they can never disagree.
 *
 * An obligation's status combines three things: whether it's completed, the state of its own
 * submissions (for an activity with a folder: missing, rejected, waiting for approval, accepted)
 * and the activity's window for this teacher (not open yet, open, late, closed — see
 * ActivityWindowChecker). A submission waiting for approval is someone else's move, so it's never
 * shown (or reminded about) as overdue.
 */
final class ActivityObligationFinder
{
    private const string SUBMISSION_MISSING   = 'missing';
    private const string SUBMISSION_REJECTED  = 'rejected';
    private const string SUBMISSION_IN_REVIEW = 'in_review';
    private const string SUBMISSION_ACCEPTED  = 'accepted';

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityWindowChecker $windows,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<ActivityDashboardItem> every obligation $teacher owns in $centre, in no particular order */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $items = [];
        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            foreach ($this->forActivity($teacher, $activity) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return list<ActivityDashboardItem> one per owner row $teacher holds for $activity; empty if it isn't theirs */
    public function forActivity(Teacher $teacher, Activity $activity): array
    {
        $owners = $this->completion->getMyOwnedObligations($teacher, $activity);
        if ($owners === []) {
            return [];
        }

        $window       = $this->windows->for($activity, $teacher);
        $categoryPath = $this->categoryPath($activity->getCategory());
        $daysLeft     = $this->daysUntil($window->endDate);

        $items = [];
        foreach ($owners as $owner) {
            $items[] = new ActivityDashboardItem(
                $activity,
                $this->statusOf($activity, $owner, $window),
                $categoryPath,
                $owner['label'],
                $window->endDate,
                $window->startDate,
                $window->graceUntil,
                $daysLeft,
            );
        }

        return $items;
    }

    /**
     * The most urgent status among $teacher's own obligations for $activity (see
     * ActivityObligationStatus::urgency()) — so it only reads "completed" once all of them are.
     * Null when the activity isn't theirs at all.
     */
    public function worstStatusFor(Teacher $teacher, Activity $activity): ?ActivityObligationStatus
    {
        $worst = null;
        foreach ($this->forActivity($teacher, $activity) as $item) {
            if ($worst === null || $item->status->urgency() < $worst->urgency()) {
                $worst = $item->status;
            }
        }

        return $worst;
    }

    /** @param array{profile: ?\App\Entity\SpecificProfile, listItem: ?\App\Entity\ListItem, teacher: ?Teacher, label: ?string, key: string} $owner */
    private function statusOf(Activity $activity, array $owner, ActivityWindow $window): ActivityObligationStatus
    {
        if ($this->completion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher'])) {
            return ActivityObligationStatus::Completed;
        }

        $submission = $this->submissionState($activity, $owner);
        if ($submission === self::SUBMISSION_IN_REVIEW) {
            return ActivityObligationStatus::InReview;
        }
        if ($window->notStarted) {
            return ActivityObligationStatus::Upcoming;
        }
        if ($window->blocked) {
            return ActivityObligationStatus::Closed;
        }
        if ($submission === self::SUBMISSION_REJECTED) {
            return ActivityObligationStatus::Rejected;
        }
        if ($this->clock->now() > $window->endDate) {
            return $window->late ? ActivityObligationStatus::Late : ActivityObligationStatus::Overdue;
        }

        return ActivityObligationStatus::Open;
    }

    /**
     * The combined state of the owner's own submissions, worst first: any rejected one has to be
     * resubmitted; else any missing one is still to do; else any waiting for approval means it's
     * the reviewer's move. An activity without a folder has no submissions: always "missing".
     *
     * @param array{profile: ?\App\Entity\SpecificProfile, listItem: ?\App\Entity\ListItem, teacher: ?Teacher, label: ?string, key: string} $owner
     */
    private function submissionState(Activity $activity, array $owner): string
    {
        if ($activity->getFolder() === null) {
            return self::SUBMISSION_MISSING;
        }

        $states = [];
        foreach ($this->completion->getAllSlots($activity) as $slot) {
            $owns = $owner['teacher'] !== null
                ? $slot->teacher === $owner['teacher']
                : ($slot->profile === $owner['profile'] && $slot->listItem === $owner['listItem'] && $slot->teacher === null);
            if ($owns) {
                $states[] = $this->documentState($this->completion->resolveSlot($activity, $slot));
            }
        }

        foreach ([self::SUBMISSION_REJECTED, self::SUBMISSION_MISSING, self::SUBMISSION_IN_REVIEW] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return $states === [] ? self::SUBMISSION_MISSING : self::SUBMISSION_ACCEPTED;
    }

    private function documentState(?Document $document): string
    {
        return match (true) {
            $document === null                    => self::SUBMISSION_MISSING,
            $document->isPendingApproval()        => self::SUBMISSION_IN_REVIEW,
            $document->getActiveRevision() !== null => self::SUBMISSION_ACCEPTED,
            default                               => self::SUBMISSION_REJECTED,
        };
    }

    /** Whole days from today to $date's day: 0 today, 1 tomorrow, negative once past. */
    private function daysUntil(\DateTimeImmutable $date): int
    {
        $today = $this->clock->now()->setTime(0, 0);

        return (int) $today->diff($date->setTime(0, 0))->format('%r%a');
    }

    private function categoryPath(ActivityCategory $category): string
    {
        $trail = [];
        for ($c = $category; $c !== null; $c = $c->getParent()) {
            array_unshift($trail, $c->getName());
        }

        return implode(' › ', $trail);
    }
}
