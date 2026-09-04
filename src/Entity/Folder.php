<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A folder inside a document-tree section, holding documents. Four independent
 * profile/subprofile restriction lists control who can do what:
 * responsible profiles (manage the folder's documents, upload with any upload
 * profile), upload profiles (upload only), visibility profiles (if non-empty,
 * only these can see the folder at all) and review profiles (if non-empty,
 * uploaded revisions need one of these to approve/reject before publishing).
 * A fifth, independent restriction — $allowedFormats — controls WHAT can be uploaded rather than
 * who: when non-empty, an upload is rejected unless it matches one of a fixed set of file formats
 * (see AllowedFileFormat).
 * The folder's own configuration (this entity's fields) is edited separately
 * from its content — see FolderVoter vs EducationalCentreVoter::RESPONSIBILITIES.
 */
#[ORM\Entity(repositoryClass: FolderRepository::class)]
class Folder
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(inversedBy: 'folders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentSection $documentSection;

    /** Documents grouped by upload profile when displayed. */
    #[ORM\Column]
    private bool $groupByProfile = false;

    /** Whether a future automatic archiving pass moves this folder's documents to its history. */
    #[ORM\Column]
    private bool $autoArchive = false;

    /** Hidden by default; only quality managers/admins can reveal it. */
    #[ORM\Column]
    private bool $obsolete = false;

    /**
     * Optional HTML description shown to anyone browsing the folder. Stored raw (edited via the
     * Quill rich-text editor, same as a richtext-typed setting value); sanitize with
     * sanitize_html('app.rich_text') at every render site, never on write — this mirrors the
     * existing settings-value and PdfHeaderBuilder convention in this codebase.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Comma-separated AllowedFileFormat::value strings uploads are restricted to — null/empty
     * means no restriction (any format accepted). A plain nullable string column rather than
     * Doctrine's built-in SIMPLE_ARRAY type: the fixed, closed set of possible values (see
     * AllowedFileFormat) never contains a comma, so a manual split/join is exactly as safe and
     * keeps the mapped property a plain nullable string instead of an array whose own conversion
     * rules (SimpleArrayType::convertToDatabaseValue() turns an empty array into SQL NULL) make
     * "array, but the column allows NULL" impossible to express cleanly. Use
     * getAllowedFormats()/setAllowedFormats() for the typed enum API — nothing outside this class
     * reads the raw string.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $allowedFormats = null;

    /** @var Collection<int, FolderResponsibleProfile> */
    #[ORM\OneToMany(targetEntity: FolderResponsibleProfile::class, mappedBy: 'folder', cascade: ['persist'], orphanRemoval: true)]
    private Collection $responsibleProfiles;

    /** @var Collection<int, FolderUploadProfile> */
    #[ORM\OneToMany(targetEntity: FolderUploadProfile::class, mappedBy: 'folder', cascade: ['persist'], orphanRemoval: true)]
    private Collection $uploadProfiles;

    /** @var Collection<int, FolderVisibilityProfile> */
    #[ORM\OneToMany(targetEntity: FolderVisibilityProfile::class, mappedBy: 'folder', cascade: ['persist'], orphanRemoval: true)]
    private Collection $visibilityProfiles;

    /** @var Collection<int, FolderReviewProfile> */
    #[ORM\OneToMany(targetEntity: FolderReviewProfile::class, mappedBy: 'folder', cascade: ['persist'], orphanRemoval: true)]
    private Collection $reviewProfiles;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'folder', cascade: ['persist'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * Inverse side of Activity::$folder — Activity owns the relation. When set, this folder's
     * documents ARE that activity's submissions: uploading a new document directly (from the
     * document tree) is blocked in favour of going through the activity (see FolderController /
     * _folder_documents.html.twig).
     */
    #[ORM\OneToOne(mappedBy: 'folder')]
    private ?Activity $activity = null;

    public function __construct()
    {
        $this->responsibleProfiles = new ArrayCollection();
        $this->uploadProfiles      = new ArrayCollection();
        $this->visibilityProfiles  = new ArrayCollection();
        $this->reviewProfiles      = new ArrayCollection();
        $this->documents           = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->documentSection->getEducationalCentre();
    }

    public function getDocumentSection(): DocumentSection
    {
        return $this->documentSection;
    }

    public function setDocumentSection(DocumentSection $documentSection): static
    {
        $this->documentSection = $documentSection;

        return $this;
    }

    public function isGroupByProfile(): bool
    {
        return $this->groupByProfile;
    }

    public function setGroupByProfile(bool $groupByProfile): static
    {
        $this->groupByProfile = $groupByProfile;

        return $this;
    }

    public function isAutoArchive(): bool
    {
        return $this->autoArchive;
    }

    public function setAutoArchive(bool $autoArchive): static
    {
        $this->autoArchive = $autoArchive;

        return $this;
    }

    public function isObsolete(): bool
    {
        return $this->obsolete;
    }

    public function setObsolete(bool $obsolete): static
    {
        $this->obsolete = $obsolete;

        return $this;
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

    public function isFormatRestricted(): bool
    {
        return $this->allowedFormats !== null && $this->allowedFormats !== '';
    }

    /** @return list<AllowedFileFormat> */
    public function getAllowedFormats(): array
    {
        if (!$this->isFormatRestricted()) {
            return [];
        }

        return array_map(
            static fn (string $value): AllowedFileFormat => AllowedFileFormat::from($value),
            explode(',', (string) $this->allowedFormats),
        );
    }

    /** @param list<AllowedFileFormat> $formats */
    public function setAllowedFormats(array $formats): static
    {
        $values = array_values(array_unique(array_map(
            static fn (AllowedFileFormat $format): string => $format->value,
            $formats,
        )));

        $this->allowedFormats = $values === [] ? null : implode(',', $values);

        return $this;
    }

    /**
     * Whether a file named $filename, reported as $mimeType, would be accepted for upload —
     * always true when the folder has no format restriction. Matches by extension OR by MIME
     * type (not both): a browser's reported MIME type is sometimes a generic fallback like
     * application/octet-stream even for a well-formed file, so requiring both would reject valid
     * uploads the extension alone already identifies correctly.
     */
    public function acceptsFile(string $filename, string $mimeType): bool
    {
        if (!$this->isFormatRestricted()) {
            return true;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType  = strtolower($mimeType);

        foreach ($this->getAllowedFormats() as $format) {
            if (in_array($extension, $format->extensions(), true) || in_array($mimeType, $format->mimeTypes(), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function isLeaf(): bool
    {
        return $this->documents->isEmpty();
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    // ── Responsible profiles ─────────────────────────────────────────────────

    /** @return Collection<int, FolderResponsibleProfile> */
    public function getResponsibleProfiles(): Collection
    {
        return $this->responsibleProfiles;
    }

    public function hasResponsibleProfile(SpecificProfile $profile, ?ListItem $listItem): bool
    {
        return $this->responsibleProfiles->exists(
            static fn (int $i, FolderResponsibleProfile $r): bool =>
                $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
        );
    }

    public function addResponsibleProfile(SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if (!$this->hasResponsibleProfile($profile, $listItem)) {
            $this->responsibleProfiles->add(new FolderResponsibleProfile($this, $profile, $listItem));
        }

        return $this;
    }

    public function removeResponsibleProfile(FolderResponsibleProfile $restriction): static
    {
        $this->responsibleProfiles->removeElement($restriction);

        return $this;
    }

    // ── Upload profiles ──────────────────────────────────────────────────────

    /** @return Collection<int, FolderUploadProfile> */
    public function getUploadProfiles(): Collection
    {
        return $this->uploadProfiles;
    }

    public function hasUploadProfile(SpecificProfile $profile, ?ListItem $listItem): bool
    {
        return $this->uploadProfiles->exists(
            static fn (int $i, FolderUploadProfile $r): bool =>
                $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
        );
    }

    public function addUploadProfile(SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if (!$this->hasUploadProfile($profile, $listItem)) {
            $this->uploadProfiles->add(new FolderUploadProfile($this, $profile, $listItem));
        }

        return $this;
    }

    public function removeUploadProfile(FolderUploadProfile $restriction): static
    {
        $this->uploadProfiles->removeElement($restriction);

        return $this;
    }

    // ── Visibility profiles ──────────────────────────────────────────────────

    /** @return Collection<int, FolderVisibilityProfile> */
    public function getVisibilityProfiles(): Collection
    {
        return $this->visibilityProfiles;
    }

    public function isVisibilityRestricted(): bool
    {
        return !$this->visibilityProfiles->isEmpty();
    }

    public function hasVisibilityProfile(SpecificProfile $profile, ?ListItem $listItem): bool
    {
        return $this->visibilityProfiles->exists(
            static fn (int $i, FolderVisibilityProfile $r): bool =>
                $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
        );
    }

    public function addVisibilityProfile(SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if (!$this->hasVisibilityProfile($profile, $listItem)) {
            $this->visibilityProfiles->add(new FolderVisibilityProfile($this, $profile, $listItem));
        }

        return $this;
    }

    public function removeVisibilityProfile(FolderVisibilityProfile $restriction): static
    {
        $this->visibilityProfiles->removeElement($restriction);

        return $this;
    }

    // ── Review profiles ──────────────────────────────────────────────────────

    /** @return Collection<int, FolderReviewProfile> */
    public function getReviewProfiles(): Collection
    {
        return $this->reviewProfiles;
    }

    public function requiresReview(): bool
    {
        return !$this->reviewProfiles->isEmpty();
    }

    public function hasReviewProfile(SpecificProfile $profile, ?ListItem $listItem): bool
    {
        return $this->reviewProfiles->exists(
            static fn (int $i, FolderReviewProfile $r): bool =>
                $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
        );
    }

    public function addReviewProfile(SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if (!$this->hasReviewProfile($profile, $listItem)) {
            $this->reviewProfiles->add(new FolderReviewProfile($this, $profile, $listItem));
        }

        return $this;
    }

    public function removeReviewProfile(FolderReviewProfile $restriction): static
    {
        $this->reviewProfiles->removeElement($restriction);

        return $this;
    }
}
