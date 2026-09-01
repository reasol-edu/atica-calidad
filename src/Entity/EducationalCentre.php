<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EducationalCentreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EducationalCentreRepository::class)]
class EducationalCentre
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 8, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $city;

    #[ORM\ManyToOne]
    private ?AcademicYear $activeAcademicYear = null;

    // Not EXTRA_LAZY on purpose: contains() is called many times per request
    // (voters, repositories, TenantContextExtension) on the same centre/teacher;
    // with EXTRA_LAZY each call would trigger its own query, whereas with normal
    // lazy loading the first call hydrates the whole collection (small, limited
    // to the centre's staff with a special role) and the rest is resolved in
    // memory.
    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'educational_centre_admins')]
    private Collection $admins;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'educational_centre_quality_managers')]
    private Collection $qualityManagers;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'educational_centre_internal_auditors')]
    private Collection $internalAuditors;

    public function __construct()
    {
        $this->admins = new ArrayCollection();
        $this->qualityManagers = new ArrayCollection();
        $this->internalAuditors = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
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

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getActiveAcademicYear(): ?AcademicYear
    {
        return $this->activeAcademicYear;
    }

    public function requireActiveAcademicYear(): AcademicYear
    {
        return $this->activeAcademicYear
            ?? throw new \LogicException('This educational centre has no active academic year.');
    }

    public function setActiveAcademicYear(?AcademicYear $academicYear): static
    {
        if ($academicYear !== null && $academicYear->getEducationalCentre() !== $this) {
            throw new \LogicException('The academic year does not belong to this educational centre.');
        }

        $this->activeAcademicYear = $academicYear;

        return $this;
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function getAdmins(): Collection
    {
        return $this->admins;
    }

    public function addAdmin(Teacher $admin): static
    {
        if (!$this->admins->contains($admin)) {
            $this->admins->add($admin);
        }

        return $this;
    }

    public function removeAdmin(Teacher $admin): static
    {
        $this->admins->removeElement($admin);

        return $this;
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function getQualityManagers(): Collection
    {
        return $this->qualityManagers;
    }

    public function addQualityManager(Teacher $teacher): static
    {
        if (!$this->qualityManagers->contains($teacher)) {
            $this->qualityManagers->add($teacher);
        }

        return $this;
    }

    public function removeQualityManager(Teacher $teacher): static
    {
        $this->qualityManagers->removeElement($teacher);

        return $this;
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function getInternalAuditors(): Collection
    {
        return $this->internalAuditors;
    }

    public function addInternalAuditor(Teacher $teacher): static
    {
        if (!$this->internalAuditors->contains($teacher)) {
            $this->internalAuditors->add($teacher);
        }

        return $this;
    }

    public function removeInternalAuditor(Teacher $teacher): static
    {
        $this->internalAuditors->removeElement($teacher);

        return $this;
    }
}
