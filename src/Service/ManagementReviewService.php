<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ManagementReview;
use App\Entity\Teacher;
use App\Repository\ImprovementActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The management review's life: the quality manager or the management team schedules it (when,
 * which period it looks back on), writes down during the meeting what the application can't know
 * and its conclusions, records its decisions as improvement plan actions, and the management team
 * closes it — freezing the inputs compiled by ManagementReviewBuilder, so the record stays as it
 * was discussed. A closed review can no longer be changed or deleted.
 */
final class ManagementReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly ManagementReviewBuilder $builder,
        private readonly ImprovementActionRepository $actions,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function create(EducationalCentre $centre, AcademicYear $year, Teacher $actor, string $title, \DateTimeImmutable $heldOn, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): ManagementReview
    {
        $review = new ManagementReview($centre, $year, trim($title), $heldOn, $periodStart, $periodEnd, $actor, $this->clock->now());
        $this->em->persist($review);
        $this->em->flush();

        $this->activityLogger->record('management_review.create', ['review' => $review->getTitle()], $centre);

        return $review;
    }

    /** Saves its schedule and its texts; only while it's open. */
    public function save(
        ManagementReview $review,
        string $title,
        \DateTimeImmutable $heldOn,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        ?string $attendees,
        ?string $contextChanges,
        ?string $satisfaction,
        ?string $suppliers,
        ?string $resources,
        ?string $conclusions,
    ): void {
        if ($review->isClosed()) {
            throw new \LogicException('A closed management review cannot be changed.');
        }
        $review->schedule(trim($title), $heldOn, $periodStart, $periodEnd)
            ->write(self::text($attendees), self::text($contextChanges), self::text($satisfaction), self::text($suppliers), self::text($resources), self::text($conclusions));
        $this->em->flush();

        $this->activityLogger->record('management_review.update', ['review' => $review->getTitle()], $review->getEducationalCentre());
    }

    /** Deletes an open review. Its decisions stay in the improvement plan, no longer linked to it. */
    public function delete(ManagementReview $review): void
    {
        if ($review->isClosed()) {
            throw new \LogicException('A closed management review cannot be deleted.');
        }
        foreach ($this->actions->findByManagementReview($review) as $action) {
            $action->setManagementReview(null);
        }
        $title  = $review->getTitle();
        $centre = $review->getEducationalCentre();
        $this->em->remove($review);
        $this->em->flush();

        $this->activityLogger->record('management_review.delete', ['review' => $title], $centre);
    }

    /**
     * Why it can't be closed yet (translation keys, "quality" domain); empty when it can.
     *
     * @return list<string>
     */
    public function blockers(ManagementReview $review): array
    {
        $blockers = [];
        if ($review->getConclusions() === null) {
            $blockers[] = 'review.blocker.conclusions';
        }
        if ($review->getHeldOn() > $this->clock->now()) {
            $blockers[] = 'review.blocker.not_held';
        }

        return $blockers;
    }

    /** Closes it, freezing the inputs as they are now. */
    public function close(ManagementReview $review, Teacher $actor): void
    {
        if ($review->isClosed() || $this->blockers($review) !== []) {
            throw new \LogicException('This management review cannot be closed.');
        }
        $review->close($actor, $this->clock->now(), $this->builder->build($review));
        $this->em->flush();

        $this->activityLogger->record('management_review.close', ['review' => $review->getTitle()], $review->getEducationalCentre());
    }

    private static function text(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
