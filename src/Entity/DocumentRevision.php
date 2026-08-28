<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * One version of a Document's file. When the owning folder has review profiles, a newly created
 * revision starts pending ($pendingReview = true) until a reviewer approves it (becomes the
 * document's active revision — see Document::setActiveRevision()) or rejects it ($rejected = true,
 * can never become active). $reviewedBy records whichever teacher resolved it, in either
 * direction — the request only calls for a single field, not separate approver/rejecter fields.
 * $uploadedBy and $revisedAt are editable after the fact by an admin/responsable de calidad (see
 * SectionBrowserComponent::saveEditRevision()), to correct who a revision is attributed to or
 * back-date one entered late.
 */
#[ORM\Entity(repositoryClass: DocumentRevisionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_document_revision_version', columns: ['document_id', 'version'])]
class DocumentRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $revisedAt;

    #[ORM\Column]
    private bool $pendingReview;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $uploadedBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $reviewedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $rejected = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewResult = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private DocumentFile $file;

    public function __construct(Document $document, int $version, DocumentFile $file, bool $pendingReview, Teacher $uploadedBy)
    {
        $this->document      = $document;
        $this->version       = $version;
        $this->file          = $file;
        $this->pendingReview = $pendingReview;
        $this->uploadedBy    = $uploadedBy;
        $this->revisedAt     = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getRevisedAt(): \DateTimeImmutable
    {
        return $this->revisedAt;
    }

    public function setRevisedAt(\DateTimeImmutable $revisedAt): static
    {
        $this->revisedAt = $revisedAt;

        return $this;
    }

    public function isPendingReview(): bool
    {
        return $this->pendingReview;
    }

    public function getUploadedBy(): Teacher
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(Teacher $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getReviewedBy(): ?Teacher
    {
        return $this->reviewedBy;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function getReviewResult(): ?string
    {
        return $this->reviewResult;
    }

    /** Approves this revision: no longer pending, not rejected, publishable as the active one. */
    public function approve(Teacher $reviewer, ?string $reviewResult): static
    {
        $this->pendingReview = false;
        $this->rejected      = false;
        $this->reviewedBy    = $reviewer;
        $this->reviewResult  = $reviewResult;

        return $this;
    }

    /** Rejects this revision: it can never become the document's active revision. */
    public function reject(Teacher $reviewer, ?string $reviewResult): static
    {
        $this->pendingReview = false;
        $this->rejected      = true;
        $this->reviewedBy    = $reviewer;
        $this->reviewResult  = $reviewResult;

        return $this;
    }

    /** Whether this revision could be picked as the document's active revision. */
    public function isApproved(): bool
    {
        return !$this->pendingReview && !$this->rejected;
    }

    public function getFile(): DocumentFile
    {
        return $this->file;
    }
}
