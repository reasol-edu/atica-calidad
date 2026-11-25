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
     * getEffectiveProfileAssociations() for what applies to an item once its ancestors' are added.
     *
     * @var Collection<int, ListItemProfile>
     */
    #[ORM\OneToMany(targetEntity: ListItemProfile::class, mappedBy: 'listItem', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $profileAssociations;

    /**
     * The teachers associated with this item (who teach it). Accumulate along the branch like the
     * profile associations — see getEffectiveTeachers() — and only mean something in the activities
     * that ask for them (Activity::isTeachersDeliver()).
     *
     * @var Collection<int, ListItemTeacher>
     */
    #[ORM\OneToMany(targetEntity: ListItemTeacher::class, mappedBy: 'listItem', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $teacherAssociations;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    public function __construct()
    {
        $this->children            = new ArrayCollection();
        $this->profileAssociations = new ArrayCollection();
        $this->teacherAssociations = new ArrayCollection();
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
     * The associations that apply to this item: its own plus those of every ancestor (they
     * accumulate; nothing is hidden by an association of its own), own ones first, without repeating
     * a (profile, subprofile) pair.
     *
     * @return Collection<int, ListItemProfile>
     */
    public function getEffectiveProfileAssociations(): Collection
    {
        $result = [];
        $keys   = [];
        foreach ($this->selfAndAncestors() as $node) {
            foreach ($node->getProfileAssociations() as $association) {
                $key = $association->getKey();
                if (!isset($keys[$key])) {
                    $keys[$key] = true;
                    $result[]   = $association;
                }
            }
        }

        return new ArrayCollection($result);
    }

    /**
     * What this item inherits: pairs of the ancestor it comes from and the association, closest
     * ancestor first, leaving out what the item already has as its own (and what a closer ancestor
     * already contributed).
     *
     * @return list<array{item: self, association: ListItemProfile}>
     */
    public function getInheritedProfileAssociations(): array
    {
        $keys = [];
        foreach ($this->profileAssociations as $association) {
            $keys[$association->getKey()] = true;
        }

        $result = [];
        foreach ($this->selfAndAncestors() as $node) {
            if ($node === $this) {
                continue;
            }
            foreach ($node->getProfileAssociations() as $association) {
                $key = $association->getKey();
                if (!isset($keys[$key])) {
                    $keys[$key] = true;
                    $result[]   = ['item' => $node, 'association' => $association];
                }
            }
        }

        return $result;
    }

    /**
     * The closest ancestor association that makes $own (one of this item's own) redundant: the same
     * (profile, subprofile) pair — `exact` — or the same profile with an intermediate subprofile
     * that covers $own's (its subprofile is that node or hangs from it in the profile's list), which
     * is a broader association: then the own one only narrows nothing and can go.
     *
     * @return array{item: self, association: ListItemProfile, exact: bool}|null
     */
    public function getAncestorCovering(ListItemProfile $own): ?array
    {
        $ownKey       = $own->getKey();
        $ownSub       = $own->getSubprofile();
        $ownSubChain  = [];
        $seen         = [];
        for ($n = $ownSub; $n !== null && !isset($seen[spl_object_id($n)]); $n = $n->parent) {
            $seen[spl_object_id($n)] = true;
            $ownSubChain[$n->getId()->toRfc4122()] = true;
        }

        foreach ($this->selfAndAncestors() as $node) {
            if ($node === $this) {
                continue;
            }
            foreach ($node->getProfileAssociations() as $association) {
                if ($association->getKey() === $ownKey) {
                    return ['item' => $node, 'association' => $association, 'exact' => true];
                }
                $sub = $association->getSubprofile();
                if ($sub !== null && $ownSub !== null && $association->getSpecificProfile() === $own->getSpecificProfile() && isset($ownSubChain[$sub->getId()->toRfc4122()])) {
                    return ['item' => $node, 'association' => $association, 'exact' => false];
                }
            }
        }

        return null;
    }

    /** True when this item inherits some association from an ancestor that it does not have as its own. */
    public function hasInheritedAssociation(): bool
    {
        $own = [];
        foreach ($this->profileAssociations as $association) {
            $own[$association->getKey()] = true;
        }
        foreach ($this->selfAndAncestors() as $node) {
            if ($node === $this) {
                continue;
            }
            foreach ($node->getProfileAssociations() as $association) {
                if (!isset($own[$association->getKey()])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<Teacher> this item's own teachers (not the inherited ones), in the order they were added */
    public function getTeachers(): array
    {
        return array_values(array_map(
            static fn (ListItemTeacher $a): Teacher => $a->getTeacher(),
            $this->teacherAssociations->toArray(),
        ));
    }

    public function hasTeacher(Teacher $teacher): bool
    {
        return $this->teacherAssociations->exists(
            static fn (int $i, ListItemTeacher $a): bool => $a->getTeacher()->getId()->equals($teacher->getId())
        );
    }

    public function addTeacher(Teacher $teacher): static
    {
        if (!$this->hasTeacher($teacher)) {
            $this->teacherAssociations->add(new ListItemTeacher($this, $teacher));
        }

        return $this;
    }

    public function removeTeacher(Teacher $teacher): static
    {
        foreach ($this->teacherAssociations as $association) {
            if ($association->getTeacher()->getId()->equals($teacher->getId())) {
                $this->teacherAssociations->removeElement($association);
            }
        }

        return $this;
    }

    public function clearTeachers(): static
    {
        $this->teacherAssociations->clear();

        return $this;
    }

    /**
     * What this item inherits in teachers: pairs of the ancestor they come from and the teacher,
     * closest ancestor first, leaving out those the item already has as its own (and those a closer
     * ancestor already contributed).
     *
     * @return list<array{item: self, teacher: Teacher}>
     */
    public function getInheritedTeachers(): array
    {
        $seen = [];
        foreach ($this->teacherAssociations as $association) {
            $seen[$association->getTeacher()->getId()->toRfc4122()] = true;
        }

        $result = [];
        foreach ($this->selfAndAncestors() as $node) {
            if ($node === $this) {
                continue;
            }
            foreach ($node->teacherAssociations as $association) {
                $teacher = $association->getTeacher();
                $key     = $teacher->getId()->toRfc4122();
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[]   = ['item' => $node, 'teacher' => $teacher];
                }
            }
        }

        return $result;
    }

    /**
     * The teachers that apply to this item: its own plus those of every ancestor, own ones first,
     * without repeating anyone. Whether each is in the active course is not decided here (that is
     * SlotResponsibility's).
     *
     * @return list<Teacher>
     */
    public function getEffectiveTeachers(): array
    {
        return array_merge(
            $this->getTeachers(),
            array_map(static fn (array $pair): Teacher => $pair['teacher'], $this->getInheritedTeachers()),
        );
    }

    /**
     * This item followed by its ancestors, closest first, guarded against cycles.
     *
     * @return list<self>
     */
    private function selfAndAncestors(): array
    {
        $nodes = [];
        $seen  = [];
        for ($node = $this; $node !== null && !isset($seen[spl_object_id($node)]); $node = $node->parent) {
            $seen[spl_object_id($node)] = true;
            $nodes[]                    = $node;
        }

        return $nodes;
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
