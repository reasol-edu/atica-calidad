<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Audit;
use App\Entity\Finding;
use App\Entity\ImprovementAction;
use App\Entity\Indicator;
use App\Entity\Measurement;
use App\Entity\MeasurementPeriod;

/**
 * Something a teacher has to do in "Mejora continua" (QualityTaskFinder): classify a report,
 * analyse a nonconformity, carry out an action — a finding's, or one of the improvement plan,
 * which has no finding — check whether the actions worked, record an indicator's value for a
 * period, decide what to do about one off target, prepare and carry out an internal audit, or
 * approve the year's audit programme. Shown with the activities in "Tus próximos
 * pasos", the bell, the calendar and the daily reminder, with the same status colours.
 */
final readonly class QualityTask
{
    public const string CLASSIFY = 'classify';
    public const string ANALYZE  = 'analyze';
    public const string ACTION   = 'action';
    public const string VERIFY   = 'verify';
    public const string MEASURE  = 'measure';
    public const string REVIEW   = 'review';
    public const string AUDIT    = 'audit';
    public const string APPROVE  = 'approve';

    /** Days before the due date from which a task is "due soon". */
    public const int SOON_DAYS = 7;

    public function __construct(
        public string $type,
        /** Null for an improvement plan action and for the indicator tasks. */
        public ?Finding $finding,
        public ?ImprovementAction $action,
        public ?\DateTimeImmutable $dueDate,
        /** 'overdue', 'soon' or 'open' — as ActivityObligationStatus's overdue / open — or, only in the calendar, 'done'. */
        public string $urgency,
        /** MEASURE and REVIEW: the indicator, the period, and (REVIEW, or MEASURE done) its value. */
        public ?Indicator $indicator = null,
        public ?MeasurementPeriod $period = null,
        public ?Measurement $measurement = null,
        /** AUDIT: the audit to prepare and carry out (or, in the calendar, the one of theirs, or where they're audited); APPROVE: any of the programme's. */
        public ?Audit $audit = null,
    ) {}

    public function label(): string
    {
        if ($this->type === self::APPROVE) {
            return $this->audit?->getProgram()->getAcademicYear()->getName() ?? '';
        }

        return $this->audit?->getTitle() ?? $this->indicator?->getName() ?? $this->action?->getDescription() ?? $this->finding?->getTitle() ?? '';
    }

    /**
     * What tells it apart, shown under its label: the finding's code (NC-2026-014), the plan
     * action's (PM-2026-003) or the indicator's period ("1.ª evaluación"); null while unclassified.
     */
    public function code(): ?string
    {
        return match (true) {
            $this->type === self::APPROVE => null,
            $this->audit !== null   => $this->audit->getCode(),
            $this->period !== null  => $this->period->getName(),
            $this->finding !== null => $this->finding->getCode(),
            default                 => $this->action?->getCode(),
        };
    }

    /** Most urgent first, then soonest due, then oldest. */
    public static function compare(self $a, self $b): int
    {
        $rank = ['overdue' => 0, 'soon' => 1, 'open' => 2, 'done' => 3];

        return [$rank[$a->urgency], $a->dueDate ?? new \DateTimeImmutable('9999-12-31'), $a->since()]
            <=> [$rank[$b->urgency], $b->dueDate ?? new \DateTimeImmutable('9999-12-31'), $b->since()];
    }

    private function since(): \DateTimeImmutable
    {
        return $this->finding?->getReportedAt()
            ?? $this->action?->getCreatedAt()
            ?? $this->measurement?->getRecordedAt()
            ?? $this->period?->getEndDate()
            ?? $this->audit?->getCreatedAt()
            ?? new \DateTimeImmutable('9999-12-31');
    }
}
