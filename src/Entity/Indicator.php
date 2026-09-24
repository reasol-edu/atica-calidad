<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndicatorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Something the centre measures to know how a process is going (ISO 9001 9.1): what it is, how
 * it's worked out, its unit, whether more is better, and who records it — a teacher or anyone
 * holding a profile. What it aims for and when it's measured change every year (IndicatorTarget);
 * its values are Measurements, one per period.
 */
#[ORM\Entity(repositoryClass: IndicatorRepository::class)]
class Indicator
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\Column(length: 255)]
    private string $name;

    /** How it's worked out, in words: "alumnado que promociona / alumnado matriculado × 100". */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** The process (document tree section) it measures. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentSection $section = null;

    /** "%", "puntos", "días"… or nothing. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column]
    private bool $higherIsBetter = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $responsibleTeacher = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpecificProfile $responsibleProfile = null;

    /** An indicator no longer measured stays for its history, but asks for nothing. */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, IndicatorTarget> */
    #[ORM\OneToMany(targetEntity: IndicatorTarget::class, mappedBy: 'indicator', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $targets;

    public function __construct(EducationalCentre $centre, string $name, \DateTimeImmutable $createdAt)
    {
        $this->educationalCentre = $centre;
        $this->name              = $name;
        $this->createdAt         = $createdAt;
        $this->targets           = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSection(): ?DocumentSection
    {
        return $this->section;
    }

    public function setSection(?DocumentSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function isHigherBetter(): bool
    {
        return $this->higherIsBetter;
    }

    public function setHigherIsBetter(bool $higherIsBetter): static
    {
        $this->higherIsBetter = $higherIsBetter;

        return $this;
    }

    public function getResponsibleTeacher(): ?Teacher
    {
        return $this->responsibleTeacher;
    }

    public function getResponsibleProfile(): ?SpecificProfile
    {
        return $this->responsibleProfile;
    }

    /** A teacher or a profile, never both. */
    public function assignTo(?Teacher $teacher, ?SpecificProfile $profile): static
    {
        $this->responsibleTeacher = $teacher;
        $this->responsibleProfile = $teacher === null ? $profile : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, IndicatorTarget> */
    public function getTargets(): Collection
    {
        return $this->targets;
    }

    public function targetFor(AcademicYear $year): ?IndicatorTarget
    {
        foreach ($this->targets as $target) {
            if ($target->getAcademicYear() === $year || $target->getAcademicYear()->getId()->equals($year->getId())) {
                return $target;
            }
        }

        return null;
    }

    /** $year's target, created empty if it had none. */
    public function targetForOrNew(AcademicYear $year): IndicatorTarget
    {
        $target = $this->targetFor($year);
        if ($target === null) {
            $target = new IndicatorTarget($this, $year);
            $this->targets->add($target);
        }

        return $target;
    }

    /** "87,5 %", "7,2 puntos", "1.200" — Spanish decimals, up to two. */
    public function format(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        $number = self::number($value, '.');

        return $this->unit === null || $this->unit === '' ? $number : ($this->unit === '%' ? $number . ' %' : $number . ' ' . $this->unit);
    }

    /** 87.5 → "87,5", 100.0 → "100": up to two decimals, no trailing zeros; $thousands between thousands. */
    public static function number(float $value, string $thousands = ''): string
    {
        $number = number_format($value, 2, ',', $thousands);

        return str_contains($number, ',') ? rtrim(rtrim($number, '0'), ',') : $number;
    }
}
