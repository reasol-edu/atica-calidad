<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Finding;
use App\Entity\ImprovementAction;

/**
 * Something a teacher has to do in "Mejora continua" (QualityTaskFinder): classify a report,
 * analyse a nonconformity, carry out an action, or check whether the actions worked. Shown with
 * the activities in "Tus próximos pasos", with the same status colours.
 */
final readonly class QualityTask
{
    public const string CLASSIFY = 'classify';
    public const string ANALYZE  = 'analyze';
    public const string ACTION   = 'action';
    public const string VERIFY   = 'verify';

    /** Days before the due date from which a task is "due soon". */
    public const int SOON_DAYS = 7;

    public function __construct(
        public string $type,
        public Finding $finding,
        public ?ImprovementAction $action,
        public ?\DateTimeImmutable $dueDate,
        /** 'overdue', 'soon' or 'open' — as ActivityObligationStatus's overdue / open. */
        public string $urgency,
    ) {}

    public function label(): string
    {
        return $this->action?->getDescription() ?? $this->finding->getTitle();
    }

    /** Most urgent first, then soonest due, then oldest reported. */
    public static function compare(self $a, self $b): int
    {
        $rank = ['overdue' => 0, 'soon' => 1, 'open' => 2];

        return [$rank[$a->urgency], $a->dueDate ?? new \DateTimeImmutable('9999-12-31'), $a->finding->getReportedAt()]
            <=> [$rank[$b->urgency], $b->dueDate ?? new \DateTimeImmutable('9999-12-31'), $b->finding->getReportedAt()];
    }
}
