<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One period of a measurement calendar ("1.ª evaluación", "Final 2", "Octubre"): what a measurement
 * refers to. Once it has ended, each indicator on its calendar asks for a value; the deadline to
 * record it is its end plus quality.measurement_days.
 */
#[ORM\Entity]
class MeasurementPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'periods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MeasurementCalendar $calendar;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column]
    private int $position;

    public function __construct(MeasurementCalendar $calendar, string $name, \DateTimeImmutable $start, \DateTimeImmutable $end, int $position)
    {
        $this->calendar  = $calendar;
        $this->name      = $name;
        $this->startDate = $start;
        $this->endDate   = $end;
        $this->position  = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCalendar(): MeasurementCalendar
    {
        return $this->calendar;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(string $name, \DateTimeImmutable $start, \DateTimeImmutable $end, int $position): static
    {
        $this->name      = $name;
        $this->startDate = $start;
        $this->endDate   = $end;
        $this->position  = $position;

        return $this;
    }

    /** Over by $today (compared by day): its value can be asked for. */
    public function hasEnded(\DateTimeImmutable $today): bool
    {
        return $this->endDate <= $today->setTime(0, 0);
    }
}
