<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A highlighted period of a PrintableCalendar: a description, a background colour, and its dates
 * either as a plain range (DateRange) or as one end plus a Monday-Friday hours pattern, walked day
 * by day (skipping weekends and the academic year's non-working days) until totalHours is reached
 * — see PrintableCalendarPdfBuilder for how those days, and the optional "jornadas" summary shown
 * above the calendar when showJourneySummary is set, are computed.
 */
#[ORM\Entity]
class PrintableCalendarPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'periods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PrintableCalendar $calendar;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 7)]
    private string $color;

    #[ORM\Column]
    private bool $showJourneySummary;

    #[ORM\Column(enumType: PrintableCalendarPeriodMode::class)]
    private PrintableCalendarPeriodMode $mode;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate;

    #[ORM\Column(nullable: true)]
    private ?float $totalHours;

    #[ORM\Column(nullable: true)]
    private ?float $mondayHours;

    #[ORM\Column(nullable: true)]
    private ?float $tuesdayHours;

    #[ORM\Column(nullable: true)]
    private ?float $wednesdayHours;

    #[ORM\Column(nullable: true)]
    private ?float $thursdayHours;

    #[ORM\Column(nullable: true)]
    private ?float $fridayHours;

    #[ORM\Column]
    private int $position;

    public function __construct(
        PrintableCalendar $calendar,
        string $description,
        string $color,
        bool $showJourneySummary,
        PrintableCalendarPeriodMode $mode,
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?float $totalHours,
        ?float $mondayHours,
        ?float $tuesdayHours,
        ?float $wednesdayHours,
        ?float $thursdayHours,
        ?float $fridayHours,
        int $position,
    ) {
        $this->calendar           = $calendar;
        $this->description        = $description;
        $this->color              = $color;
        $this->showJourneySummary = $showJourneySummary;
        $this->mode               = $mode;
        $this->startDate          = $startDate;
        $this->endDate            = $endDate;
        $this->totalHours         = $totalHours;
        $this->mondayHours        = $mondayHours;
        $this->tuesdayHours       = $tuesdayHours;
        $this->wednesdayHours     = $wednesdayHours;
        $this->thursdayHours      = $thursdayHours;
        $this->fridayHours        = $fridayHours;
        $this->position           = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function isShowJourneySummary(): bool
    {
        return $this->showJourneySummary;
    }

    public function setShowJourneySummary(bool $showJourneySummary): static
    {
        $this->showJourneySummary = $showJourneySummary;

        return $this;
    }

    public function getMode(): PrintableCalendarPeriodMode
    {
        return $this->mode;
    }

    public function setMode(PrintableCalendarPeriodMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getTotalHours(): ?float
    {
        return $this->totalHours;
    }

    public function setTotalHours(?float $totalHours): static
    {
        $this->totalHours = $totalHours;

        return $this;
    }

    /** @return array<int, ?float> hours for ISO weekday 1 (Monday) .. 5 (Friday) */
    public function weekdayHours(): array
    {
        return [
            1 => $this->mondayHours,
            2 => $this->tuesdayHours,
            3 => $this->wednesdayHours,
            4 => $this->thursdayHours,
            5 => $this->fridayHours,
        ];
    }

    public function getMondayHours(): ?float
    {
        return $this->mondayHours;
    }

    public function setMondayHours(?float $hours): static
    {
        $this->mondayHours = $hours;

        return $this;
    }

    public function getTuesdayHours(): ?float
    {
        return $this->tuesdayHours;
    }

    public function setTuesdayHours(?float $hours): static
    {
        $this->tuesdayHours = $hours;

        return $this;
    }

    public function getWednesdayHours(): ?float
    {
        return $this->wednesdayHours;
    }

    public function setWednesdayHours(?float $hours): static
    {
        $this->wednesdayHours = $hours;

        return $this;
    }

    public function getThursdayHours(): ?float
    {
        return $this->thursdayHours;
    }

    public function setThursdayHours(?float $hours): static
    {
        $this->thursdayHours = $hours;

        return $this;
    }

    public function getFridayHours(): ?float
    {
        return $this->fridayHours;
    }

    public function setFridayHours(?float $hours): static
    {
        $this->fridayHours = $hours;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
