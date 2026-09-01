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
     * A loose cross-reference from this item to a profile or subprofile (e.g. a "Matemáticas" item
     * associated with the "Jefatura de departamento" subprofile it falls under) — unrelated to the
     * parent/child hierarchy. Mirrors the (profile, listItem) pair used everywhere else in the app
     * to identify "a profile, or one specific subprofile of it" (see ProfileAssignmentRow):
     * $associatedProfileListItem is only ever set alongside a non-null $associatedProfile, and only
     * to one of that profile's own leaf descendants (see setAssociation()). Deleting either the
     * profile or the list item just clears the corresponding column back to null (ON DELETE SET
     * NULL) instead of blocking the deletion — but deleting the leaf alone would otherwise leave
     * $associatedProfile dangling on its own, pointing at a list-associated profile with no
     * subprofile chosen, which is never a state setAssociation() allows in the first place; the
     * getters below paper over that by treating it as no association at all (see
     * hasConsistentAssociation()).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpecificProfile $associatedProfile = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $associatedProfileListItem = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    /**
     * Tags attached directly to this item — inherited by every descendant
     * (see getEffectiveTags()). Attachable at any depth, not just leaves.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'list_item_tag')]
    private Collection $tags;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->tags     = new ArrayCollection();
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

    /**
     * A list-associated profile's own subprofile leaf is a separate row (see
     * $associatedProfileListItem), which the database only nulls on ITS OWN deletion — deleting
     * the leaf doesn't also touch $associatedProfile. Without this check, that would silently turn
     * "associated with subprofile X of profile Y" into "associated with the whole of profile Y",
     * which is never a valid state for a list-associated profile (setAssociation() never allows
     * setting one without a subprofile) — so treat it as no association at all instead.
     */
    private function hasConsistentAssociation(): bool
    {
        if ($this->associatedProfile === null) {
            return true;
        }

        return $this->associatedProfile->getListItem() === null || $this->associatedProfileListItem !== null;
    }

    public function getAssociatedProfile(): ?SpecificProfile
    {
        return $this->hasConsistentAssociation() ? $this->associatedProfile : null;
    }

    /** The specific subprofile ("Jefatura de departamento" leaf) the association points to — null if unset, or if it points at a plain, non-list-associated profile. */
    public function getAssociatedProfileListItem(): ?self
    {
        return $this->hasConsistentAssociation() ? $this->associatedProfileListItem : null;
    }

    public function isAssociated(): bool
    {
        return $this->getAssociatedProfile() !== null;
    }

    /**
     * Associates this item with a profile — either a plain one ($listItem left null) or, for a
     * list-associated profile, one specific subprofile ($listItem, one of the profile's own leaf
     * descendants). Pass null to clear the association entirely.
     */
    public function setAssociation(?SpecificProfile $profile, ?ListItem $listItem = null): static
    {
        if ($profile === null && $listItem !== null) {
            throw new \LogicException('A subperfil cannot be set without its profile.');
        }

        if ($profile !== null && $profile->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be associated with a profile from another centre.');
        }

        if ($listItem !== null && $listItem->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be associated with a subperfil from another centre.');
        }

        $this->associatedProfile         = $profile;
        $this->associatedProfileListItem = $listItem;

        return $this;
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

    /** @return Collection<int, Tag> tags attached directly to this item (not inherited ones) */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /**
     * Tags visible on this item: its own, plus every ancestor's.
     *
     * @return Collection<int, Tag>
     */
    public function getEffectiveTags(): Collection
    {
        $effective = new ArrayCollection($this->tags->toArray());
        for ($ancestor = $this->parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
            foreach ($ancestor->getTags() as $tag) {
                if (!$effective->contains($tag)) {
                    $effective->add($tag);
                }
            }
        }

        return $effective;
    }
}
