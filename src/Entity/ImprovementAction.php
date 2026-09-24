<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImprovementActionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Something to do, by someone, by a date, with evidence of it done: the repair and corrective
 * actions of a Finding, or (without one) a preventive or improvement action of the centre's
 * improvement plan. Its responsible is a teacher or a profile — anyone holding that profile can
 * carry it out, as with "by profile" activity submissions.
 */
#[ORM\Entity(repositoryClass: ImprovementActionRepository::class)]
#[ORM\Index(columns: ['educational_centre_id', 'status'], name: 'idx_improvement_action_centre_status')]
class ImprovementAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne(inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Finding $finding;

    #[ORM\Column(enumType: ImprovementActionType::class)]
    private ImprovementActionType $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $responsibleTeacher = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpecificProfile $responsibleProfile = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(enumType: ImprovementActionStatus::class)]
    private ImprovementActionStatus $status = ImprovementActionStatus::Pending;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $doneBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    /** What was actually done, written when marking it done. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $result = null;

    /** @var Collection<int, QualityAttachment> */
    #[ORM\OneToMany(targetEntity: QualityAttachment::class, mappedBy: 'action')]
    #[ORM\OrderBy(['uploadedAt' => 'ASC'])]
    private Collection $attachments;

    public function __construct(EducationalCentre $centre, ?Finding $finding, ImprovementActionType $type, string $description, ?Teacher $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->educationalCentre = $centre;
        $this->finding           = $finding;
        $this->type              = $type;
        $this->description       = $description;
        $this->createdBy         = $createdBy;
        $this->createdAt         = $createdAt;
        $this->attachments       = new ArrayCollection();
        $finding?->getActions()->add($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getFinding(): ?Finding
    {
        return $this->finding;
    }

    public function getType(): ImprovementActionType
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
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

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getStatus(): ImprovementActionStatus
    {
        return $this->status;
    }

    public function isDone(): bool
    {
        return $this->status === ImprovementActionStatus::Done;
    }

    public function start(): static
    {
        if ($this->status === ImprovementActionStatus::Pending) {
            $this->status = ImprovementActionStatus::InProgress;
        }

        return $this;
    }

    public function complete(Teacher $by, \DateTimeImmutable $at, ?string $result): static
    {
        $this->status = ImprovementActionStatus::Done;
        $this->doneBy = $by;
        $this->doneAt = $at;
        $this->result = $result;

        return $this;
    }

    public function getCreatedBy(): ?Teacher
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDoneBy(): ?Teacher
    {
        return $this->doneBy;
    }

    public function getDoneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    /** @return Collection<int, QualityAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }
}
