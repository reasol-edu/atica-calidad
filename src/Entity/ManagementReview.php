<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ManagementReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A management review (ISO 9001 9.3): when it was held, the period it looks back on, who took
 * part, what the management team says about what the application can't know (changes in the
 * context, satisfaction, external providers, resources) and its conclusions. The rest of its
 * inputs are compiled from the application (ManagementReviewBuilder) — live while it's a draft,
 * frozen in $snapshot once the management team closes it. Its decisions are improvement plan
 * actions linked to it (ImprovementAction::getManagementReview()).
 */
#[ORM\Entity(repositoryClass: ManagementReviewRepository::class)]
class ManagementReview
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

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $heldOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodEnd;

    /**
     * The six texts below (attendees, contextChanges, satisfaction, suppliers, resources,
     * conclusions) hold HTML from the Quill rich-text editor, same as Folder::$description and a
     * richtext-typed setting value; stored raw, sanitize with sanitize_html('app.rich_text') at
     * every render site, never on write.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $attendees = null;

    /** 9.3.2 b: changes in external and internal issues. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contextChanges = null;

    /** 9.3.2 c 1: satisfaction and feedback from pupils, families and staff. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $satisfaction = null;

    /** 9.3.2 c 7: performance of external providers. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suppliers = null;

    /** 9.3.2 d: adequacy of resources. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resources = null;

    /** 9.3.3: conclusions on the system's suitability, adequacy and effectiveness. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conclusions = null;

    /**
     * The compiled inputs as they were when it was closed (ManagementReviewBuilder); null while a draft.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $snapshot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $closedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(EducationalCentre $centre, AcademicYear $year, string $title, \DateTimeImmutable $heldOn, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, ?Teacher $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->educationalCentre = $centre;
        $this->academicYear      = $year;
        $this->title             = $title;
        $this->heldOn            = $heldOn;
        $this->periodStart       = $periodStart;
        $this->periodEnd         = $periodEnd;
        $this->createdBy         = $createdBy;
        $this->createdAt         = $createdAt;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getHeldOn(): \DateTimeImmutable
    {
        return $this->heldOn;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function schedule(string $title, \DateTimeImmutable $heldOn, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): static
    {
        $this->title       = $title;
        $this->heldOn      = $heldOn;
        $this->periodStart = $periodStart;
        $this->periodEnd   = $periodEnd;

        return $this;
    }

    public function getAttendees(): ?string
    {
        return $this->attendees;
    }

    public function getContextChanges(): ?string
    {
        return $this->contextChanges;
    }

    public function getSatisfaction(): ?string
    {
        return $this->satisfaction;
    }

    public function getSuppliers(): ?string
    {
        return $this->suppliers;
    }

    public function getResources(): ?string
    {
        return $this->resources;
    }

    public function getConclusions(): ?string
    {
        return $this->conclusions;
    }

    /** What the management team writes: every text but the schedule. */
    public function write(?string $attendees, ?string $contextChanges, ?string $satisfaction, ?string $suppliers, ?string $resources, ?string $conclusions): static
    {
        $this->attendees      = $attendees;
        $this->contextChanges = $contextChanges;
        $this->satisfaction   = $satisfaction;
        $this->suppliers      = $suppliers;
        $this->resources      = $resources;
        $this->conclusions    = $conclusions;

        return $this;
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }

    /** @return array<string, mixed>|null */
    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function close(Teacher $by, \DateTimeImmutable $at, array $snapshot): static
    {
        $this->closedBy = $by;
        $this->closedAt = $at;
        $this->snapshot = $snapshot;

        return $this;
    }

    public function getClosedBy(): ?Teacher
    {
        return $this->closedBy;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedBy(): ?Teacher
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
