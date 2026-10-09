<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ReviewQueueItem;
use Symfony\Component\Clock\ClockInterface;

/**
 * The revisions waiting for review, most pressing first: those of an activity whose deadline is past
 * or within a week (a submission that may still be late for what it's for), then the ones that have
 * been waiting longest, then the rest — each group oldest first. Whose queue it is comes from
 * PendingReviewFinder: the revisions a teacher is personally a reviewer of, or — for whoever may
 * review anything (administration, quality) — every one in the centre.
 */
class ReviewQueueBuilder
{
    /** An activity due within this many days (or past due) puts its submissions first. */
    public const int DEADLINE_DAYS = 7;
    /** Waiting this long or more puts a revision ahead of the recent ones. */
    public const int WAITING_DAYS = 7;

    public function __construct(
        private readonly PendingReviewFinder $pending,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ClockInterface $clock,
    ) {}

    /** Whether $teacher may see the queue of the whole centre rather than just their own. */
    public function canSeeAll(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $this->access->isAdminOrQualityManager($teacher, $centre);
    }

    /** @return list<ReviewQueueItem> */
    public function build(Teacher $teacher, EducationalCentre $centre, bool $all = false): array
    {
        $revisions = $all && $this->canSeeAll($teacher, $centre)
            ? $this->pending->allPendingForCentre($centre)
            : $this->pending->forTeacher($teacher, $centre);

        $today = $this->clock->now()->setTime(0, 0);
        $items = array_map(fn (DocumentRevision $r): ReviewQueueItem => $this->item($r, $today), $revisions);

        $rank = [ReviewQueueItem::CRITICAL => 0, ReviewQueueItem::WAITING => 1, ReviewQueueItem::NORMAL => 2];
        usort($items, static fn (ReviewQueueItem $a, ReviewQueueItem $b): int => [$rank[$a->urgency], $a->revision->getRevisedAt()] <=> [$rank[$b->urgency], $b->revision->getRevisedAt()]);

        return $items;
    }

    private function item(DocumentRevision $revision, \DateTimeImmutable $today): ReviewQueueItem
    {
        $activity    = $revision->getDocument()->getFolder()->getActivity();
        $deadline    = $activity === null ? null : $this->deadline->currentCycleEndDate($activity);
        $daysTo      = $deadline === null ? null : (int) $today->diff($deadline->setTime(0, 0))->format('%r%a');
        $waitingDays = max(0, (int) $revision->getRevisedAt()->setTime(0, 0)->diff($today)->format('%r%a'));

        return new ReviewQueueItem(
            $revision,
            match (true) {
                $daysTo !== null && $daysTo <= self::DEADLINE_DAYS => ReviewQueueItem::CRITICAL,
                $waitingDays >= self::WAITING_DAYS                 => ReviewQueueItem::WAITING,
                default                                            => ReviewQueueItem::NORMAL,
            },
            $waitingDays,
            $activity,
            $deadline,
            $daysTo,
        );
    }
}
