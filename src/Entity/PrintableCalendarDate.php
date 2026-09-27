<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A single highlighted date of a PrintableCalendar: its own background colour and a description,
 * shown in the PDF's month grid and in that month's side annotation. The same date can appear in
 * several entries (different things happening the same day) — when it does, the controller keeps
 * their colour in sync on save (CalendarGeneratorController), but each entry is still listed
 * separately in the side annotation, never merged.
 */
#[ORM\Entity]
class PrintableCalendarDate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'dates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PrintableCalendar $calendar;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 7)]
    private string $color;

    #[ORM\Column(length: 255)]
    private string $description;

    public function __construct(PrintableCalendar $calendar, \DateTimeImmutable $date, string $color, string $description)
    {
        $this->calendar    = $calendar;
        $this->date        = $date;
        $this->color       = $color;
        $this->description = $description;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
