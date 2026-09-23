<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityDashboardSummary;
use App\Model\ActivityObligationStatus;

/**
 * Builds the dashboard's "next steps" widget: how the teacher's obligations stand overall, and
 * the few most urgent ones they can act on right now — the full list lives in "Mis actividades".
 * Statuses come from ActivityObligationFinder, like everywhere else.
 */
final class ActivityDashboardSummaryBuilder
{
    public const int MAX_NEXT_STEPS = 5;

    public function __construct(
        private readonly ActivityObligationFinder $obligations,
    ) {}

    public function build(Teacher $teacher, EducationalCentre $centre): ActivityDashboardSummary
    {
        $items = $this->obligations->forTeacher($teacher, $centre);

        $actionable = array_values(array_filter($items, static fn (ActivityDashboardItem $i): bool => $i->status->isActionable()));
        usort($actionable, ActivityDashboardItem::compareByUrgency(...));

        $upcoming = array_values(array_filter($items, static fn (ActivityDashboardItem $i): bool => $i->status === ActivityObligationStatus::Upcoming));
        usort($upcoming, static fn (ActivityDashboardItem $a, ActivityDashboardItem $b): int => $a->startsAt <=> $b->startsAt);

        return new ActivityDashboardSummary(
            total: count($items),
            todo: count($actionable),
            overdue: count(array_filter($actionable, static fn (ActivityDashboardItem $i): bool => $i->status->isOverdue())),
            inReview: count(array_filter($items, static fn (ActivityDashboardItem $i): bool => $i->status === ActivityObligationStatus::InReview)),
            completed: count(array_filter($items, static fn (ActivityDashboardItem $i): bool => $i->status === ActivityObligationStatus::Completed)),
            nextSteps: array_slice($actionable, 0, self::MAX_NEXT_STEPS),
            nextUpcoming: $upcoming[0] ?? null,
        );
    }
}
