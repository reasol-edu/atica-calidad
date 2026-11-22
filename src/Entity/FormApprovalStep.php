<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormApprovalStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * One step of the approval chain a model defines, for one delivered revision: the steps are created when the document is delivered,
 * in order, the first pending and the rest waiting. Whoever holds the step's profile decides it; the last approval approves the
 * revision itself and any rejection rejects it (and the chain starts again from step 1 with the next delivery).
 */
#[ORM\Entity(repositoryClass: FormApprovalStepRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_form_approval_step_position', columns: ['revision_id', 'position'])]
class FormApprovalStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormSubmissionRevision $revision;

    #[ORM\Column]
    private int $position;

    /** What the step is called in the model ("Jefatura del DACE"); kept even if the profile is deleted. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpecificProfile $profile;

    #[ORM\Column(length: 10, enumType: FormApprovalStepState::class)]
    private FormApprovalStepState $state;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(FormSubmissionRevision $revision, int $position, string $name, ?SpecificProfile $profile, FormApprovalStepState $state)
    {
        $this->revision = $revision;
        $this->position = $position;
        $this->name     = $name;
        $this->profile  = $profile;
        $this->state    = $state;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRevision(): FormSubmissionRevision
    {
        return $this->revision;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProfile(): ?SpecificProfile
    {
        return $this->profile;
    }

    public function getState(): FormApprovalStepState
    {
        return $this->state;
    }

    public function activate(): static
    {
        $this->state = FormApprovalStepState::Pending;

        return $this;
    }

    public function decide(FormApprovalStepState $state, Teacher $by, ?string $note): static
    {
        $this->state     = $state;
        $this->decidedBy = $by;
        $this->decidedAt = now();
        $this->note      = $note;

        return $this;
    }

    public function cancel(): static
    {
        $this->state = FormApprovalStepState::Cancelled;

        return $this;
    }

    public function getDecidedBy(): ?Teacher
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
