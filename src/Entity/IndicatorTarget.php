<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An indicator in one academic year: the calendar it's measured on, what it aims for (target) and
 * from where it's worrying (alert threshold, between the target and "off target"). An indicator
 * without one for a year isn't measured that year.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_indicator_target_year', columns: ['indicator_id', 'academic_year_id'])]
class IndicatorTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'targets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Indicator $indicator;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** When it's measured this year; none, and it asks for nothing. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MeasurementCalendar $calendar = null;

    #[ORM\Column(nullable: true)]
    private ?float $target = null;

    #[ORM\Column(nullable: true)]
    private ?float $alertThreshold = null;

    public function __construct(Indicator $indicator, AcademicYear $year)
    {
        $this->indicator    = $indicator;
        $this->academicYear = $year;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIndicator(): Indicator
    {
        return $this->indicator;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function getCalendar(): ?MeasurementCalendar
    {
        return $this->calendar;
    }

    public function setCalendar(?MeasurementCalendar $calendar): static
    {
        $this->calendar = $calendar;

        return $this;
    }

    public function getTarget(): ?float
    {
        return $this->target;
    }

    public function getAlertThreshold(): ?float
    {
        return $this->alertThreshold;
    }

    public function setGoals(?float $target, ?float $alertThreshold): static
    {
        $this->target         = $target;
        $this->alertThreshold = $target === null ? null : $alertThreshold;

        return $this;
    }

    /**
     * How $value stands: reaching the target is on target; short of it but not past the threshold,
     * an alert; past the threshold (or short of the target, with no threshold), off target. With
     * no target there's nothing to compare with: on target.
     */
    public function statusOf(float $value): IndicatorStatus
    {
        if ($this->target === null) {
            return IndicatorStatus::OnTarget;
        }

        $higher = $this->indicator->isHigherBetter();
        if ($higher ? $value >= $this->target : $value <= $this->target) {
            return IndicatorStatus::OnTarget;
        }
        if ($this->alertThreshold !== null && ($higher ? $value >= $this->alertThreshold : $value <= $this->alertThreshold)) {
            return IndicatorStatus::Alert;
        }

        return IndicatorStatus::OffTarget;
    }
}
