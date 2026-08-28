<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentSectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A node in a centre's document tree (e.g. "Calidad" → "Procedimientos" → "PR-01"), of arbitrary
 * depth — there is no separate "tree" container entity: a root section (no parent) and its
 * descendants together form what the user thinks of as "a section of the tree". Optionally
 * associated with one or more specific profiles/subperfiles (see DocumentSectionProfile); what
 * that association means for read access is resolved elsewhere, once browsing is built — this
 * entity only captures the association.
 */
#[ORM\Entity(repositoryClass: DocumentSectionRepository::class)]
class DocumentSection
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

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    /** @var Collection<int, DocumentSectionProfile> */
    #[ORM\OneToMany(targetEntity: DocumentSectionProfile::class, mappedBy: 'documentSection', cascade: ['persist'], orphanRemoval: true)]
    private Collection $profileRestrictions;

    /** @var Collection<int, Folder> */
    #[ORM\OneToMany(targetEntity: Folder::class, mappedBy: 'documentSection', cascade: ['persist'], orphanRemoval: true)]
    private Collection $folders;

    public function __construct()
    {
        $this->children             = new ArrayCollection();
        $this->profileRestrictions  = new ArrayCollection();
        $this->folders              = new ArrayCollection();
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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Depth is unbounded, but the tree must stay a tree: the new parent cannot be this section
     * itself or any of its own descendants.
     */
    public function setParent(?self $parent): static
    {
        if ($parent !== null && $parent->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A document section cannot be attached to a parent from another centre.');
        }

        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $this) {
                throw new \LogicException('A document section cannot become a descendant of itself.');
            }
        }

        $this->parent = $parent;

        return $this;
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    public function isLeaf(): bool
    {
        return $this->children->isEmpty();
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function setEducationalCentre(EducationalCentre $educationalCentre): static
    {
        $this->educationalCentre = $educationalCentre;

        return $this;
    }

    /** @return Collection<int, DocumentSectionProfile> */
    public function getProfileRestrictions(): Collection
    {
        return $this->profileRestrictions;
    }

    public function isRestricted(): bool
    {
        return !$this->profileRestrictions->isEmpty();
    }

    public function hasProfileRestriction(SpecificProfile $profile, ?ListItem $listItem): bool
    {
        return $this->profileRestrictions->exists(
            static fn (int $i, DocumentSectionProfile $r): bool =>
                $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
        );
    }

    public function addProfileRestriction(SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if (!$this->hasProfileRestriction($profile, $listItem)) {
            $this->profileRestrictions->add(new DocumentSectionProfile($this, $profile, $listItem));
        }

        return $this;
    }

    public function removeProfileRestriction(DocumentSectionProfile $restriction): static
    {
        $this->profileRestrictions->removeElement($restriction);

        return $this;
    }

    /** @return Collection<int, Folder> */
    public function getFolders(): Collection
    {
        return $this->folders;
    }
}
