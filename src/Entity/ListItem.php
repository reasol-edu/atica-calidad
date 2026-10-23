<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ListItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A node in a centre's custom hierarchy of named lists (e.g. "Grupo" →
 * "1º ESO" → "1º ESO-A"), of arbitrary depth — there is no separate "List"
 * container entity: a root item (no parent) and its descendants together
 * form what the user thinks of as "a list".
 */
#[ORM\Entity(repositoryClass: ListItemRepository::class)]
class ListItem
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

    #[ORM\Column]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    /**
     * The profiles/subprofiles this item is associated with (e.g. a "Matemáticas" item with the
     * "Jefatura de departamento" subprofile it falls under) — loose cross-references unrelated to
     * the parent/child hierarchy, any number of them. See ListItemProfile and
     * getEffectiveProfileAssociations() for what applies to an item that has none of its own.
     *
     * @var Collection<int, ListItemProfile>
     */
    #[ORM\OneToMany(targetEntity: ListItemProfile::class, mappedBy: 'listItem', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $profileAssociations;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    public function __construct()
    {
        $this->children            = new ArrayCollection();
        $this->profileAssociations = new ArrayCollection();
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Depth is unbounded, but the tree must stay a tree: the new parent
     * cannot be this item itself or any of its own descendants.
     */
    public function setParent(?self $parent): static
    {
        if ($parent !== null && $parent->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be attached to a parent from another centre.');
        }

        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $this) {
                throw new \LogicException('A list item cannot become a descendant of itself.');
            }
        }

        $this->parent = $parent;

        return $this;
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    /** @return Collection<int, ListItemProfile> this item's own associations (not the inherited ones) */
    public function getProfileAssociations(): Collection
    {
        return $this->profileAssociations;
    }

    /** Whether this item has associations of its own. */
    public function isAssociated(): bool
    {
        return !$this->profileAssociations->isEmpty();
    }

    public function hasProfileAssociation(SpecificProfile $profile, ?ListItem $subprofile): bool
    {
        return $this->profileAssociations->exists(
            static fn (int $i, ListItemProfile $a): bool => $a->getSpecificProfile() === $profile && $a->getSubprofile() === $subprofile
        );
    }

    /**
     * Adds an association — a plain profile ($subprofile left null) or, for a list-associated
     * profile, a subprofile of it: one of the profile's own leaf descendants, or an intermediate
     * node above some of them, meaning all of those leaves. A list-associated profile always names
     * where in its list, never the profile as a whole.
     */
    public function addProfileAssociation(SpecificProfile $profile, ?ListItem $subprofile = null): static
    {
        if ($profile->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be associated with a profile from another centre.');
        }
        if ($subprofile !== null && $subprofile->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be associated with a subprofile from another centre.');
        }
        if ($subprofile === null && $profile->getListItem() !== null) {
            throw new \LogicException('A list-associated profile must be associated through one of its subprofiles.');
        }

        if (!$this->hasProfileAssociation($profile, $subprofile)) {
            $this->profileAssociations->add(new ListItemProfile($this, $profile, $subprofile));
        }

        return $this;
    }

    public function removeProfileAssociation(ListItemProfile $association): static
    {
        $this->profileAssociations->removeElement($association);

        return $this;
    }

    public function clearProfileAssociations(): static
    {
        $this->profileAssociations->clear();

        return $this;
    }

    /**
     * Replaces every association with this single one — or with none, for a null profile. A
     * shortcut for the common "this item belongs to exactly this" case.
     */
    public function setAssociation(?SpecificProfile $profile, ?ListItem $subprofile = null): static
    {
        if ($profile === null && $subprofile !== null) {
            throw new \LogicException('A subprofile cannot be set without its profile.');
        }

        $this->clearProfileAssociations();
        if ($profile !== null) {
            $this->addProfileAssociation($profile, $subprofile);
        }

        return $this;
    }

    /**
     * The item whose associations apply to this one: itself when it has some, otherwise its
     * nearest ancestor that does — an element with none of its own takes those of the closest
     * parent that has them (and only those: its own, when it has any, are never added to). Null when
     * neither it nor any ancestor has any.
     */
    public function getAssociationSource(): ?self
    {
        $seen = [];
        for ($node = $this; $node !== null && !isset($seen[spl_object_id($node)]); $node = $node->parent) {
            $seen[spl_object_id($node)] = true;
            if ($node->isAssociated()) {
                return $node;
            }
        }

        return null;
    }

    /** @return Collection<int, ListItemProfile> the associations that apply to this item: its own, or those it inherits */
    public function getEffectiveProfileAssociations(): Collection
    {
        return $this->getAssociationSource()?->getProfileAssociations() ?? new ArrayCollection();
    }

    /** True when the associations that apply to this item come from an ancestor, not from itself. */
    public function hasInheritedAssociation(): bool
    {
        $source = $this->getAssociationSource();

        return $source !== null && $source !== $this;
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
}
