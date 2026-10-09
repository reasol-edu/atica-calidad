<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityObligationStatus;
use App\Model\AgendaEntry;
use App\Model\QualityTask;

/**
 * What a teacher has to do now, activities and "Mejora continua" tasks in one list: overdue
 * first, then what's due this week (or was sent back), then the rest, each by due date. The two
 * kinds used to sit in separate blocks, so a task overdue for weeks showed below an activity due
 * in two. Statuses come from ActivityObligationFinder and QualityTaskFinder, like everywhere else.
 */
final class TeacherAgendaBuilder
{
    /** An obligation due within this many days counts as "this week". */
    public const int WEEK_DAYS = 7;

    public function __construct(
        private readonly ActivityObligationFinder $obligations,
        private readonly QualityTaskFinder $qualityTasks,
    ) {}

    /** @return list<AgendaEntry> most urgent first */
    public function build(Teacher $teacher, EducationalCentre $centre): array
    {
        $entries = [];
        foreach ($this->obligations->forTeacher($teacher, $centre) as $item) {
            if ($item->status->isActionable()) {
                $entries[] = new AgendaEntry($this->bucketOfActivity($item), $item->deadline, activity: $item);
            }
        }
        foreach ($this->qualityTasks->forTeacher($teacher, $centre) as $task) {
            $entries[] = new AgendaEntry(
                match ($task->urgency) {
                    'overdue' => AgendaEntry::OVERDUE,
                    'soon'    => AgendaEntry::WEEK,
                    default   => AgendaEntry::LATER,
                },
                $task->dueDate,
                task: $task,
            );
        }

        $rank = [AgendaEntry::OVERDUE => 0, AgendaEntry::WEEK => 1, AgendaEntry::LATER => 2];
        usort($entries, static fn (AgendaEntry $a, AgendaEntry $b): int => [$rank[$a->bucket], $a->dueDate ?? new \DateTimeImmutable('9999-12-31')]
            <=> [$rank[$b->bucket], $b->dueDate ?? new \DateTimeImmutable('9999-12-31')]);

        return $entries;
    }

    private function bucketOfActivity(ActivityDashboardItem $item): string
    {
        return match (true) {
            $item->status->isOverdue()                                                                  => AgendaEntry::OVERDUE,
            $item->status === ActivityObligationStatus::Rejected, $item->daysLeft <= self::WEEK_DAYS => AgendaEntry::WEEK,
            default                                                                                     => AgendaEntry::LATER,
        };
    }
}
