<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MeasurementBreakdownRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** The value of one group of a computed measurement (a profile, a teacher, an option): detail of the measurement, never the measurement. */
#[ORM\Entity(repositoryClass: MeasurementBreakdownRepository::class)]
class MeasurementBreakdown
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'breakdown')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Measurement $measurement;

    #[ORM\Column(length: 100)]
    private string $groupKey;

    #[ORM\Column(length: 255)]
    private string $groupLabel;

    #[ORM\Column]
    private float $value;

    /** How many deliveries (or values) the group's value is made of. */
    #[ORM\Column]
    private int $count;

    public function __construct(Measurement $measurement, string $groupKey, string $groupLabel, float $value, int $count)
    {
        $this->measurement = $measurement;
        $this->groupKey    = mb_substr($groupKey, 0, 100);
        $this->groupLabel  = mb_substr($groupLabel, 0, 255);
        $this->value       = $value;
        $this->count       = $count;
    }

    public function getMeasurement(): Measurement
    {
        return $this->measurement;
    }

    public function getGroupKey(): string
    {
        return $this->groupKey;
    }

    public function getGroupLabel(): string
    {
        return $this->groupLabel;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
