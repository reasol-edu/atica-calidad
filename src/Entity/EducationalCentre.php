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

    // Sin EXTRA_LAZY a propósito: contains() se llama muchas veces por petición
    // (voters, repositorios, TenantContextExtension) sobre el mismo centro/docente;
    // con EXTRA_LAZY cada llamada dispara su propia consulta, mientras que con la
    // carga lazy normal la primera llamada hidrata la colección entera (pequeña,
    // acotada al personal con rol especial del centro) y el resto se resuelve en
    // memoria.
    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'educational_centre_admins')]
    private Collection $admins;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'educational_centre_committee_members')]
    private Collection $committeeMembers;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'educational_centre_counselors')]
    private Collection $counselors;

    public function __construct()
    {
        $this->admins = new ArrayCollection();
        $this->committeeMembers = new ArrayCollection();
        $this->counselors = new ArrayCollection();
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
    public function getCommitteeMembers(): Collection
    {
        return $this->committeeMembers;
    }

    public function addCommitteeMember(Teacher $teacher): static
    {
        if (!$this->committeeMembers->contains($teacher)) {
            $this->committeeMembers->add($teacher);
        }

        return $this;
    }

    public function removeCommitteeMember(Teacher $teacher): static
    {
        $this->committeeMembers->removeElement($teacher);

        return $this;
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function getCounselors(): Collection
    {
        return $this->counselors;
    }

    public function addCounselor(Teacher $teacher): static
    {
        if (!$this->counselors->contains($teacher)) {
            $this->counselors->add($teacher);
        }

        return $this;
    }

    public function removeCounselor(Teacher $teacher): static
    {
        $this->counselors->removeElement($teacher);

        return $this;
    }
}
