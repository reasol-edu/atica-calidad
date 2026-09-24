<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MeasurementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An indicator's value for one period, and — when it came out off target — what the quality
 * manager made of it: a nonconformity or an improvement action (linked from them), or nothing,
 * with a reason. Until then it waits for them as a task.
 */
#[ORM\Entity(repositoryClass: MeasurementRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_measurement_indicator_period', columns: ['indicator_id', 'period_id'])]
class Measurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Indicator $indicator;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MeasurementPeriod $period;

    #[ORM\Column]
    private float $value;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $recordedBy;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    /** Set once an off-target value has been dealt with (a finding, an action, or nothing). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $reviewedBy = null;

    /** Why nothing was done, when that was the decision. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNote = null;

    public function __construct(Indicator $indicator, MeasurementPeriod $period, float $value, ?string $notes, ?Teacher $recordedBy, \DateTimeImmutable $recordedAt)
    {
        $this->indicator  = $indicator;
        $this->period     = $period;
        $this->value      = $value;
        $this->notes      = $notes;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIndicator(): Indicator
    {
        return $this->indicator;
    }

    public function getPeriod(): MeasurementPeriod
    {
        return $this->period;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /** A corrected value: whatever was decided about the old one no longer applies. */
    public function correct(float $value, ?string $notes, Teacher $by, \DateTimeImmutable $at): static
    {
        if ($value !== $this->value) {
            $this->reviewedAt = null;
            $this->reviewedBy = null;
            $this->reviewNote = null;
        }
        $this->value      = $value;
        $this->notes      = $notes;
        $this->recordedBy = $by;
        $this->recordedAt = $at;

        return $this;
    }

    public function getRecordedBy(): ?Teacher
    {
        return $this->recordedBy;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function isReviewed(): bool
    {
        return $this->reviewedAt !== null;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?Teacher
    {
        return $this->reviewedBy;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function markReviewed(Teacher $by, \DateTimeImmutable $at, ?string $note = null): static
    {
        $this->reviewedBy = $by;
        $this->reviewedAt = $at;
        $this->reviewNote = $note;

        return $this;
    }

    /** Its status against its year's target (no target for that year: on target). */
    public function status(): IndicatorStatus
    {
        $target = $this->indicator->targetFor($this->period->getCalendar()->getAcademicYear());

        return $target === null ? IndicatorStatus::OnTarget : $target->statusOf($this->value);
    }
}
