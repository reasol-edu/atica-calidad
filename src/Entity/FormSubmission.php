<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * The draft of an activity's submission filled online: who it is for (the activity, its slot and the cycle) and what has been
 * typed so far. It is NOT a Document until it is delivered (so a half-filled draft never counts as a submission, nor shows in the
 * tree): delivering it creates the Document — or its next revision — with the generated PDF, and freezes the data in a
 * FormSubmissionRevision. `draftData` is null while nobody is working on a new version.
 */
#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_form_submission_slot', columns: ['activity_id', 'slot_key', 'cycle_year'])]
class FormSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    /** ActivitySubmissionSlot::key() */
    #[ORM\Column(length: 255)]
    private string $slotKey;

    #[ORM\Column]
    private int $cycleYear;

    /** The model revision the draft (and the next delivery) is written against. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private FormModelRevision $modelRevision;

    /** Set on the first delivery. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $document = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $draftData = null;

    /** Bumped on every save: a stale tab cannot overwrite what another one saved. */
    #[ORM\Column]
    private int $draftVersion = 1;

    /**
     * Where the draft was pre-filled from, if it was: { kind: previous|document, label, revisionId } — what the copy was taken from, so
     * the page can say so and notice when the origin has since changed.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $prefilledFrom = null;

    /** Who is editing the draft right now (one person at a time): the lock lasts until `lockedUntil` and is renewed by saving. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $lockedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $updatedBy = null;

    /** @param array<string, mixed> $data */
    public function __construct(Activity $activity, string $slotKey, int $cycleYear, FormModelRevision $modelRevision, array $data)
    {
        $this->activity      = $activity;
        $this->slotKey       = $slotKey;
        $this->cycleYear     = $cycleYear;
        $this->modelRevision = $modelRevision;
        $this->draftData     = $data;
        $this->updatedAt     = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getSlotKey(): string
    {
        return $this->slotKey;
    }

    public function getCycleYear(): int
    {
        return $this->cycleYear;
    }

    public function getModelRevision(): FormModelRevision
    {
        return $this->modelRevision;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(Document $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function hasDraft(): bool
    {
        return $this->draftData !== null;
    }

    /** @return array<string, mixed>|null */
    public function getDraftData(): ?array
    {
        return $this->draftData;
    }

    /** @param array<string, mixed> $data */
    public function startDraft(FormModelRevision $modelRevision, array $data): static
    {
        $this->modelRevision = $modelRevision;
        $this->draftData     = $data;
        $this->updatedAt     = now();
        ++$this->draftVersion;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function saveDraft(array $data, ?Teacher $by): static
    {
        $this->draftData = $data;
        $this->updatedBy = $by;
        $this->updatedAt = now();
        ++$this->draftVersion;

        return $this;
    }

    public function closeDraft(): static
    {
        $this->draftData = null;
        $this->updatedAt = now();
        ++$this->draftVersion;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPrefilledFrom(): ?array
    {
        return $this->prefilledFrom;
    }

    /** @param array<string, mixed>|null $prefilledFrom */
    public function setPrefilledFrom(?array $prefilledFrom): static
    {
        $this->prefilledFrom = $prefilledFrom;

        return $this;
    }

    /** Who holds the edit lock, when it has not expired; null when the draft is free. */
    public function getLockHolder(\DateTimeImmutable $now): ?Teacher
    {
        return $this->lockedBy !== null && $this->lockedUntil !== null && $this->lockedUntil > $now ? $this->lockedBy : null;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function lock(Teacher $by, \DateTimeImmutable $until): static
    {
        $this->lockedBy    = $by;
        $this->lockedUntil = $until;

        return $this;
    }

    public function releaseLock(): static
    {
        $this->lockedBy    = null;
        $this->lockedUntil = null;

        return $this;
    }

    public function getDraftVersion(): int
    {
        return $this->draftVersion;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?Teacher
    {
        return $this->updatedBy;
    }
}
