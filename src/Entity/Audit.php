<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One internal audit of the programme: what's audited (its scope, document tree sections), in which
 * month and then on which day, by whom (a lead auditor and the team), against which checklist
 * (its AuditItems), and what came of it — the strengths, the conclusion, and the findings its
 * items became when the report was issued. Its life cycle is the "audit" state machine.
 */
#[ORM\Entity(repositoryClass: AuditRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_audit_centre_code', columns: ['educational_centre_id', 'code'])]
class Audit
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne(inversedBy: 'audits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AuditProgram $program;

    /** AI-2026-01: the programme's year and its number in it. */
    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $title;

    /** Criteria and objective: what's checked against (the standard, a procedure…) and why. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objective = null;

    /** @var Collection<int, DocumentSection> the processes audited */
    #[ORM\ManyToMany(targetEntity: DocumentSection::class)]
    #[ORM\JoinTable(name: 'audit_section')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $scope;

    /** The first day of the month it's planned for. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $plannedMonth;

    /** The day and time, once set when preparing it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $leadAuditor = null;

    /** @var Collection<int, Teacher> the rest of the audit team */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'audit_auditor')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $auditors;

    #[ORM\Column(enumType: AuditStatus::class)]
    private AuditStatus $status = AuditStatus::Planned;

    /** @var Collection<int, AuditItem> its checklist */
    #[ORM\OneToMany(targetEntity: AuditItem::class, mappedBy: 'audit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $strengths = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conclusion = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $reportIssuedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reportIssuedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(AuditProgram $program, string $code, string $title, \DateTimeImmutable $plannedMonth, \DateTimeImmutable $createdAt)
    {
        $this->program           = $program;
        $this->educationalCentre = $program->getEducationalCentre();
        $this->code              = $code;
        $this->title             = $title;
        $this->plannedMonth      = $plannedMonth->modify('first day of this month')->setTime(0, 0);
        $this->createdAt         = $createdAt;
        $this->scope             = new ArrayCollection();
        $this->auditors          = new ArrayCollection();
        $this->items             = new ArrayCollection();
        $program->getAudits()->add($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getProgram(): AuditProgram
    {
        return $this->program;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getObjective(): ?string
    {
        return $this->objective;
    }

    public function setObjective(?string $objective): static
    {
        $this->objective = $objective;

        return $this;
    }

    /** @return Collection<int, DocumentSection> */
    public function getScope(): Collection
    {
        return $this->scope;
    }

    /** @param iterable<DocumentSection> $sections */
    public function setScope(iterable $sections): static
    {
        $this->scope->clear();
        foreach ($sections as $section) {
            $this->scope->add($section);
        }

        return $this;
    }

    public function getPlannedMonth(): \DateTimeImmutable
    {
        return $this->plannedMonth;
    }

    public function setPlannedMonth(\DateTimeImmutable $month): static
    {
        $this->plannedMonth = $month->modify('first day of this month')->setTime(0, 0);

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    /** The day it's due: its date, or the last day of its month while it has none. */
    public function getDueDate(): \DateTimeImmutable
    {
        return $this->scheduledAt?->setTime(0, 0) ?? $this->plannedMonth->modify('last day of this month');
    }

    public function getLeadAuditor(): ?Teacher
    {
        return $this->leadAuditor;
    }

    public function setLeadAuditor(?Teacher $leadAuditor): static
    {
        $this->leadAuditor = $leadAuditor;

        return $this;
    }

    /** @return Collection<int, Teacher> */
    public function getAuditors(): Collection
    {
        return $this->auditors;
    }

    /** @param iterable<Teacher> $teachers */
    public function setAuditors(iterable $teachers): static
    {
        $this->auditors->clear();
        foreach ($teachers as $teacher) {
            $this->auditors->add($teacher);
        }

        return $this;
    }

    /** @return list<Teacher> the lead auditor and the rest of the team */
    public function getTeam(): array
    {
        $team = $this->leadAuditor !== null ? [$this->leadAuditor] : [];
        foreach ($this->auditors as $auditor) {
            if ($this->leadAuditor === null || !$auditor->getId()->equals($this->leadAuditor->getId())) {
                $team[] = $auditor;
            }
        }

        return $team;
    }

    public function isInTeam(Teacher $teacher): bool
    {
        foreach ($this->getTeam() as $member) {
            if ($member->getId()->equals($teacher->getId())) {
                return true;
            }
        }

        return false;
    }

    public function getStatus(): AuditStatus
    {
        return $this->status;
    }

    /** For the workflow's marking store. */
    public function setStatus(AuditStatus $status): void
    {
        $this->status = $status;
    }

    /** @return Collection<int, AuditItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(?string $clause, string $question, ?string $guidance): AuditItem
    {
        $item = new AuditItem($this, $this->items->count(), $clause, $question, $guidance);
        $this->items->add($item);

        return $item;
    }

    public function removeItem(AuditItem $item): void
    {
        $this->items->removeElement($item);
    }

    public function countAnswered(): int
    {
        return $this->items->filter(static fn (AuditItem $i): bool => $i->getResult() !== null)->count();
    }

    public function getStrengths(): ?string
    {
        return $this->strengths;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function setReport(?string $strengths, ?string $conclusion): static
    {
        $this->strengths  = $strengths;
        $this->conclusion = $conclusion;

        return $this;
    }

    public function getReportIssuedBy(): ?Teacher
    {
        return $this->reportIssuedBy;
    }

    public function getReportIssuedAt(): ?\DateTimeImmutable
    {
        return $this->reportIssuedAt;
    }

    public function markReportIssued(Teacher $by, \DateTimeImmutable $at): static
    {
        $this->reportIssuedBy = $by;
        $this->reportIssuedAt = $at;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function markClosed(\DateTimeImmutable $at): static
    {
        $this->closedAt = $at;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
