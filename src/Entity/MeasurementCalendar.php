<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MeasurementCalendarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * When the centre measures its indicators in one academic year: a named list of periods — e.g.
 * "Evaluaciones": 1.ª, 2.ª, 3.ª, Final 1 and Final 2 — each with its own dates. Started from a
 * template (MeasurementCalendarTemplates), edited freely, and copied into the next year. Each
 * indicator uses one of them in each year (IndicatorTarget).
 */
#[ORM\Entity(repositoryClass: MeasurementCalendarRepository::class)]
class MeasurementCalendar
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\Column(length: 100)]
    private string $name;

    /** @var Collection<int, MeasurementPeriod> */
    #[ORM\OneToMany(targetEntity: MeasurementPeriod::class, mappedBy: 'calendar', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $periods;

    public function __construct(EducationalCentre $centre, AcademicYear $year, string $name)
    {
        $this->educationalCentre = $centre;
        $this->academicYear      = $year;
        $this->name              = $name;
        $this->periods           = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
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

    /** @return Collection<int, MeasurementPeriod> */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }

    /** Adds a period at the end. */
    public function addPeriod(string $name, \DateTimeImmutable $start, \DateTimeImmutable $end): MeasurementPeriod
    {
        $period = new MeasurementPeriod($this, $name, $start, $end, $this->periods->count());
        $this->periods->add($period);

        return $period;
    }

    public function removePeriod(MeasurementPeriod $period): void
    {
        $this->periods->removeElement($period);
    }
}
