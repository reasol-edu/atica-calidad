<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FindingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An incident reported by anyone in the centre, and what the quality manager made of it: a
 * nonconformity (ISO 9001 10.2), an observation or an improvement opportunity — or discarded. Its
 * life cycle is the "finding" state machine (see FindingStatus); every step leaves a
 * FindingTimelineEntry, and the work to solve it is its ImprovementActions. Never deleted: a
 * mistaken report is discarded, with a reason, and stays on record.
 */
#[ORM\Entity(repositoryClass: FindingRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_finding_centre_code', columns: ['educational_centre_id', 'code'])]
#[ORM\Index(columns: ['educational_centre_id', 'status'], name: 'idx_finding_centre_status')]
class Finding
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    /** NC-2026-014, OB-…, OM-… — given when classified (FindingCodeGenerator); none while just reported or once discarded. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private string $title;

    /** "¿Qué ha pasado?", as reported. */
    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** "¿Dónde?": the process (document tree section) it concerns, if the reporter knew. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentSection $section = null;

    #[ORM\Column(enumType: FindingOrigin::class)]
    private FindingOrigin $origin = FindingOrigin::InternalReport;

    /** The off-target indicator value it was opened from, if any. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Measurement $measurement = null;

    #[ORM\Column(enumType: FindingStatus::class)]
    private FindingStatus $status = FindingStatus::Reported;

    #[ORM\Column(nullable: true, enumType: FindingKind::class)]
    private ?FindingKind $kind = null;

    #[ORM\Column(nullable: true, enumType: FindingSeverity::class)]
    private ?FindingSeverity $severity = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $reportedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $reportedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $classifiedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $classifiedAt = null;

    /** Who analyses the causes and defines the corrective actions (a nonconformity only). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $analysisResponsible = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analysisDueDate = null;

    /**
     * The "5 whys", in order, when the guided analysis was used.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $whys = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rootCause = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificationDueDate = null;

    /** Result of the latest effectiveness check: null until checked. */
    #[ORM\Column(nullable: true)]
    private ?bool $effective = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $verificationNotes = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $verifiedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $discardReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, ImprovementAction> */
    #[ORM\OneToMany(targetEntity: ImprovementAction::class, mappedBy: 'finding')]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $actions;

    /** @var Collection<int, QualityAttachment> */
    #[ORM\OneToMany(targetEntity: QualityAttachment::class, mappedBy: 'finding')]
    #[ORM\OrderBy(['uploadedAt' => 'ASC'])]
    private Collection $attachments;

    /** @var Collection<int, FindingTimelineEntry> */
    #[ORM\OneToMany(targetEntity: FindingTimelineEntry::class, mappedBy: 'finding')]
    #[ORM\OrderBy(['occurredAt' => 'ASC'])]
    private Collection $timeline;

    public function __construct(EducationalCentre $centre, string $title, string $description, ?Teacher $reportedBy, \DateTimeImmutable $reportedAt)
    {
        $this->educationalCentre = $centre;
        $this->title             = $title;
        $this->description       = $description;
        $this->reportedBy        = $reportedBy;
        $this->reportedAt        = $reportedAt;
        $this->actions           = new ArrayCollection();
        $this->attachments       = new ArrayCollection();
        $this->timeline          = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSection(): ?DocumentSection
    {
        return $this->section;
    }

    public function getMeasurement(): ?Measurement
    {
        return $this->measurement;
    }

    public function setMeasurement(?Measurement $measurement): static
    {
        $this->measurement = $measurement;

        return $this;
    }

    public function setSection(?DocumentSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getOrigin(): FindingOrigin
    {
        return $this->origin;
    }

    public function setOrigin(FindingOrigin $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    /** The "finding" workflow's marking. Change it only through the workflow. */
    public function getStatus(): FindingStatus
    {
        return $this->status;
    }

    /** @internal for the "finding" workflow's marking store */
    public function setStatus(FindingStatus $status): void
    {
        $this->status = $status;
    }

    public function getKind(): ?FindingKind
    {
        return $this->kind;
    }

    public function isNonconformity(): bool
    {
        return $this->kind === FindingKind::Nonconformity;
    }

    public function setKind(?FindingKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getSeverity(): ?FindingSeverity
    {
        return $this->severity;
    }

    public function setSeverity(?FindingSeverity $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getReportedBy(): ?Teacher
    {
        return $this->reportedBy;
    }

    public function getReportedAt(): \DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function getClassifiedBy(): ?Teacher
    {
        return $this->classifiedBy;
    }

    public function getClassifiedAt(): ?\DateTimeImmutable
    {
        return $this->classifiedAt;
    }

    public function markClassified(Teacher $by, \DateTimeImmutable $at): static
    {
        $this->classifiedBy = $by;
        $this->classifiedAt = $at;

        return $this;
    }

    public function getAnalysisResponsible(): ?Teacher
    {
        return $this->analysisResponsible;
    }

    public function setAnalysisResponsible(?Teacher $analysisResponsible): static
    {
        $this->analysisResponsible = $analysisResponsible;

        return $this;
    }

    public function getAnalysisDueDate(): ?\DateTimeImmutable
    {
        return $this->analysisDueDate;
    }

    public function setAnalysisDueDate(?\DateTimeImmutable $analysisDueDate): static
    {
        $this->analysisDueDate = $analysisDueDate;

        return $this;
    }

    /** @return list<string> */
    public function getWhys(): array
    {
        return $this->whys ?? [];
    }

    /** @param list<string> $whys */
    public function setWhys(array $whys): static
    {
        $whys       = array_values(array_filter(array_map('trim', $whys), static fn (string $w): bool => $w !== ''));
        $this->whys = $whys === [] ? null : $whys;

        return $this;
    }

    public function getRootCause(): ?string
    {
        return $this->rootCause;
    }

    public function setRootCause(?string $rootCause): static
    {
        $this->rootCause = $rootCause;

        return $this;
    }

    public function getVerificationDueDate(): ?\DateTimeImmutable
    {
        return $this->verificationDueDate;
    }

    public function setVerificationDueDate(?\DateTimeImmutable $verificationDueDate): static
    {
        $this->verificationDueDate = $verificationDueDate;

        return $this;
    }

    public function isEffective(): ?bool
    {
        return $this->effective;
    }

    public function getVerificationNotes(): ?string
    {
        return $this->verificationNotes;
    }

    public function getVerifiedBy(): ?Teacher
    {
        return $this->verifiedBy;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function recordVerification(bool $effective, string $notes, Teacher $by, \DateTimeImmutable $at): static
    {
        $this->effective         = $effective;
        $this->verificationNotes = $notes;
        $this->verifiedBy        = $by;
        $this->verifiedAt        = $at;

        return $this;
    }

    public function getDiscardReason(): ?string
    {
        return $this->discardReason;
    }

    public function setDiscardReason(?string $discardReason): static
    {
        $this->discardReason = $discardReason;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    /** @return Collection<int, ImprovementAction> */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    /** Actions still to be done (not Done). */
    public function countPendingActions(): int
    {
        return $this->actions->filter(static fn (ImprovementAction $a): bool => $a->getStatus() !== ImprovementActionStatus::Done)->count();
    }

    /**
     * A corrective action to execute after this analysis: any, the first time; one added since the
     * last verification, when that one found the previous actions ineffective — the old ones
     * didn't work, so finishing the analysis again needs something new.
     */
    public function hasCorrectiveActionForThisAnalysis(): bool
    {
        $since = $this->effective === false ? $this->verifiedAt : null;

        return $this->actions->exists(static fn (int $i, ImprovementAction $a): bool => $a->getType() === ImprovementActionType::Corrective
            && ($since === null || $a->getCreatedAt() >= $since));
    }

    /** @return Collection<int, QualityAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    /** @return Collection<int, FindingTimelineEntry> */
    public function getTimeline(): Collection
    {
        return $this->timeline;
    }
}
