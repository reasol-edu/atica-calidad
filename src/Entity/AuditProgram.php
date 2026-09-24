<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The internal audit programme of one academic year (ISO 9001 9.2.2): which processes are audited,
 * when and by whom. The quality manager prepares it; the management team approves it — and adding
 * or removing an audit afterwards asks for it again.
 */
#[ORM\Entity(repositoryClass: AuditProgramRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_audit_program_year', columns: ['academic_year_id'])]
class AuditProgram
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    /** @var Collection<int, Audit> */
    #[ORM\OneToMany(targetEntity: Audit::class, mappedBy: 'program', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['plannedMonth' => 'ASC', 'code' => 'ASC'])]
    private Collection $audits;

    public function __construct(EducationalCentre $centre, AcademicYear $year)
    {
        $this->educationalCentre = $centre;
        $this->academicYear      = $year;
        $this->audits            = new ArrayCollection();
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

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }

    public function getApprovedBy(): ?Teacher
    {
        return $this->approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function approve(Teacher $by, \DateTimeImmutable $at): static
    {
        $this->approvedBy = $by;
        $this->approvedAt = $at;

        return $this;
    }

    /** It changed: it has to be approved again. */
    public function withdrawApproval(): static
    {
        $this->approvedBy = null;
        $this->approvedAt = null;

        return $this;
    }

    /** @return Collection<int, Audit> */
    public function getAudits(): Collection
    {
        return $this->audits;
    }
}
