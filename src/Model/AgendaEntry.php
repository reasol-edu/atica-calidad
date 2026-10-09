<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One line of a teacher's agenda ("Tus próximos pasos"): either an activity obligation or a
 * "Mejora continua" task, placed in a bucket by how urgent it is — see TeacherAgendaBuilder.
 */
final readonly class AgendaEntry
{
    /** Past its deadline. */
    public const string OVERDUE = 'overdue';
    /** Due within a week, or sent back for another try. */
    public const string WEEK = 'week';
    /** Everything else that can be acted on now. */
    public const string LATER = 'later';

    public function __construct(
        public string $bucket,
        public ?\DateTimeImmutable $dueDate,
        public ?ActivityDashboardItem $activity = null,
        public ?QualityTask $task = null,
    ) {}
}
