<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * A document inside a Folder, holding one or more DocumentRevision entries. $activeRevision points
 * at whichever approved-and-not-rejected revision is currently published (nullable: a brand new
 * document, or one whose only revisions have been rejected, has none) — same self-pointer pattern
 * as EducationalCentre::$activeAcademicYear.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Folder $folder;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpecificProfile $uploadProfile = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ListItem $uploadListItem = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentRevision $activeRevision = null;

    /** @var Collection<int, DocumentRevision> */
    #[ORM\OneToMany(targetEntity: DocumentRevision::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $revisions;

    public function __construct(Folder $folder, string $name)
    {
        $this->folder     = $folder;
        $this->name       = $name;
        $this->uploadedAt = now();
        $this->revisions  = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFolder(): Folder
    {
        return $this->folder;
    }

    public function setFolder(Folder $folder): static
    {
        $this->folder = $folder;

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

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getUploadProfile(): ?SpecificProfile
    {
        return $this->uploadProfile;
    }

    public function getUploadListItem(): ?ListItem
    {
        return $this->uploadListItem;
    }

    public function setUploadProfile(?SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        $this->uploadProfile  = $profile;
        $this->uploadListItem = $profile === null ? null : $listItem;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** @return Collection<int, DocumentRevision> */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function getActiveRevision(): ?DocumentRevision
    {
        return $this->activeRevision;
    }

    /**
     * @throws \LogicException if the revision belongs to a different document, or is pending or
     *                          rejected (only an approved revision — or none — can be active)
     */
    public function setActiveRevision(?DocumentRevision $revision): static
    {
        if ($revision !== null && $revision->getDocument() !== $this) {
            throw new \LogicException('The revision does not belong to this document.');
        }
        if ($revision !== null && !$revision->isApproved()) {
            throw new \LogicException('Only an approved, non-rejected revision can be the active one.');
        }

        $this->activeRevision = $revision;

        return $this;
    }

    public function isPendingApproval(): bool
    {
        return $this->revisions->exists(
            static fn (int $i, DocumentRevision $r): bool => $r->isPendingReview()
        );
    }

    public function getPendingRevision(): ?DocumentRevision
    {
        return $this->revisions->findFirst(
            static fn (int $i, DocumentRevision $r): bool => $r->isPendingReview()
        );
    }

    public function getNextVersion(): int
    {
        $max = 0;
        foreach ($this->revisions as $revision) {
            $max = max($max, $revision->getVersion());
        }

        return $max + 1;
    }

    public function hasVersion(int $version): bool
    {
        return $this->revisions->exists(
            static fn (int $i, DocumentRevision $r): bool => $r->getVersion() === $version
        );
    }
}
