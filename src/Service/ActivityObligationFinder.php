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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

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
#[AsDoctrineListener(event: Events::postFlush)]
final class ActivityObligationFinder implements ResetInterface
{
    /**
     * A page asks for the same teacher's obligations several times (the dashboard's progress, its
     * list, the bell): kept for the request, dropped by any flush (a completion or an upload changes
     * them) and by reset().
     *
     * @var array<string, list<ActivityDashboardItem>>
     */
    private array $forTeacherMemo = [];

    public function postFlush(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->forTeacherMemo = [];
    }

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
        return $this->forTeacherMemo[$teacher->getId()->toRfc4122() . '|' . $centre->getId()->toRfc4122()] ??= $this->computeForTeacher($teacher, $centre);
    }

    /** @return list<ActivityDashboardItem> */
    private function computeForTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $items = [];
        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            foreach ($this->forActivity($teacher, $activity) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * One item per owner row $teacher holds for $activity; empty if it isn't theirs. A list-backed
     * activity's owners already come split one per (profile, listItem, leaf) — see
     * ActivityCompletionChecker::getMyOwnedObligations() — so each leaf gets its own window here
     * too, resolving its own deadline override (Activity::getDeadlineOverride()) independently of
     * its siblings under the same upload row.
     *
     * @return list<ActivityDashboardItem>
     */
    public function forActivity(Teacher $teacher, Activity $activity): array
    {
        $owners = $this->completion->getMyOwnedObligations($teacher, $activity);
        if ($owners === []) {
            return [];
        }

        $categoryPath = $this->categoryPath($activity->getCategory());

        $items = [];
        foreach ($owners as $owner) {
            $window = $this->windows->for($activity, $teacher, $owner['leaf']);
            $status  = $this->statusOf($activity, $owner, $window);
            $items[] = new ActivityDashboardItem(
                $activity,
                $status,
                $categoryPath,
                $owner['label'],
                $window->endDate,
                $window->startDate,
                $window->graceUntil,
                $this->daysUntil($window->endDate),
                $owner['profile']?->getId()->toRfc4122() ?? '',
                $owner['listItem']?->getId()->toRfc4122() ?? '',
                $owner['leaf']?->getId()->toRfc4122() ?? '',
                !$activity->requiresSubmissions() && !$activity->isAutoComplete() && $status->isActionable() && !$window->blocked,
                self::slotKeyFor($activity, $owner, $status->isActionable()),
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

    /**
     * The submission row to land on for an obligation still to do with files: its own slot key for
     * a by-profile owner (profile : subprofile : element : nobody), "next" for an individual one,
     * who may have several rows. '' for anything that needs no file from them.
     *
     * @param array{profile: ?\App\Entity\SpecificProfile, listItem: ?\App\Entity\ListItem, leaf: ?\App\Entity\ListItem, teacher: ?Teacher, label: ?string, key: string} $owner
     * @param bool $toDo whether the obligation still needs something from the teacher (the calendar passes "not completed")
     */
    public static function slotKeyFor(Activity $activity, array $owner, bool $toDo): string
    {
        if (!$activity->requiresSubmissions() || !$toDo) {
            return '';
        }
        if ($owner['profile'] === null) {
            return 'next';
        }

        return implode(':', [
            $owner['profile']->getId()->toRfc4122(),
            $owner['listItem']?->getId()->toRfc4122() ?? '',
            $owner['leaf']?->getId()->toRfc4122() ?? '',
            '',
        ]);
    }

    /** @param array{profile: ?\App\Entity\SpecificProfile, listItem: ?\App\Entity\ListItem, leaf: ?\App\Entity\ListItem, teacher: ?Teacher, label: ?string, key: string} $owner */
    private function statusOf(Activity $activity, array $owner, ActivityWindow $window): ActivityObligationStatus
    {
        if ($this->completion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher'], $owner['leaf'])) {
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
     * @param array{profile: ?\App\Entity\SpecificProfile, listItem: ?\App\Entity\ListItem, leaf: ?\App\Entity\ListItem, teacher: ?Teacher, label: ?string, key: string} $owner
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
            if ($owns && ($owner['leaf'] === null || $slot->nameListItem === $owner['leaf'])) {
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
