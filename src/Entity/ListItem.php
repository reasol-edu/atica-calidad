<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\ListItemAssociation;
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
     * The plain profiles (with no list of their own) this item is associated with. A profile that has a list is never here: the item
     * is linked to an element of that list instead ($links). See getEffectiveAssociations() for what applies once the ancestors' are
     * added.
     *
     * @var Collection<int, ListItemProfile>
     */
    #[ORM\OneToMany(targetEntity: ListItemProfile::class, mappedBy: 'listItem', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $profileAssociations;

    /**
     * The elements of other lists this item is associated with («Programación» with «Departamento › Informática» and with
     * «Evaluación › FP»): loose cross-references, any number of them, each to a node of a list its own list declares associated
     * ($linkedLists of the root). An intermediate node stands for every leaf under it.
     *
     * @var Collection<int, self>
     */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'list_item_link')]
    #[ORM\JoinColumn(name: 'list_item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'linked_item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $links;

    /**
     * Only on a root (a list): the lists its elements may be associated with («Materia» with «Departamento» and «Evaluación»). One
     * way: «Materia» → «Departamento» says nothing of «Departamento» → «Materia». A list may name itself (a department associated
     * with its own element of «Departamento»).
     *
     * @var Collection<int, self>
     */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'list_item_linked_list')]
    #[ORM\JoinColumn(name: 'list_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'linked_list_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $linkedLists;

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
        $this->links               = new ArrayCollection();
        $this->linkedLists         = new ArrayCollection();
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

    /** The list this item belongs to: its root (itself, for a root). */
    public function getRoot(): self
    {
        $root = $this;
        $seen = [spl_object_id($this) => true];
        while ($root->parent !== null && !isset($seen[spl_object_id($root->parent)])) {
            $root                    = $root->parent;
            $seen[spl_object_id($root)] = true;
        }

        return $root;
    }

    /** The item's id as text, or — not saved yet — one that tells this object apart (only to compare within a request). */
    public function identityKey(): string
    {
        return isset($this->id) ? $this->id->toRfc4122() : 'new-' . spl_object_id($this);
    }

    /** The same item: the same object, or (once saved) the same id — what an item read again after a clear() still is. */
    private static function same(self $a, self $b): bool
    {
        return $a === $b || (isset($a->id, $b->id) && $a->id->equals($b->id));
    }

    /** Whether this item is $node or hangs from it. */
    public function isUnder(self $node): bool
    {
        $seen = [];
        for ($n = $this; $n !== null && !isset($seen[spl_object_id($n)]); $n = $n->parent) {
            $seen[spl_object_id($n)] = true;
            if (self::same($n, $node)) {
                return true;
            }
        }

        return false;
    }

    // ── Associated lists (declared on the root) ──────────────────────────────

    /** @return list<self> the lists the elements of this item's list may be associated with, by name */
    public function getLinkedLists(): array
    {
        $lists = array_values($this->getRoot()->linkedLists->toArray());
        usort($lists, static fn (self $a, self $b): int => strnatcasecmp($a->getName(), $b->getName()));

        return $lists;
    }

    public function hasLinkedList(self $list): bool
    {
        $root = $list->getRoot();

        return $this->getRoot()->linkedLists->exists(static fn (int $i, self $l): bool => self::same($l, $root));
    }

    /** Declares $list (its root) associated with this item's list. */
    public function addLinkedList(self $list): static
    {
        $root = $list->getRoot();
        if ($root->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list cannot be associated with a list from another centre.');
        }
        if (!$this->hasLinkedList($root)) {
            $this->getRoot()->linkedLists->add($root);
        }

        return $this;
    }

    /** Stops associating this item's list with $list. The links of its elements to that list are not removed here: clearLinks($list) on each. */
    public function removeLinkedList(self $list): static
    {
        $root  = $list->getRoot();
        $lists = $this->getRoot()->linkedLists;
        foreach ($lists->toArray() as $linked) {
            if (self::same($linked, $root)) {
                $lists->removeElement($linked);
            }
        }

        return $this;
    }

    // ── Own associations ─────────────────────────────────────────────────────

    /** @return list<self> the elements of associated lists this item is associated with as its own, in the order they were added */
    public function getLinks(): array
    {
        return array_values($this->links->toArray());
    }

    public function hasLink(self $linked): bool
    {
        return $this->links->exists(static fn (int $i, self $l): bool => self::same($l, $linked));
    }

    /** Associates this item with $linked, an element (or intermediate node) of a list its own list declares associated. */
    public function addLink(self $linked): static
    {
        if ($linked->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be associated with an element from another centre.');
        }
        if (!$this->hasLinkedList($linked)) {
            throw new \LogicException(sprintf('The list «%s» is not associated with «%s».', $linked->getRoot()->getName(), $this->getRoot()->getName()));
        }
        if (!$this->hasLink($linked)) {
            $this->links->add($linked);
        }

        return $this;
    }

    public function removeLink(self $linked): static
    {
        foreach ($this->links->toArray() as $link) {
            if (self::same($link, $linked)) {
                $this->links->removeElement($link);
            }
        }

        return $this;
    }

    /** Removes this item's own links — all of them, or only those to elements of $list. */
    public function clearLinks(?self $list = null): static
    {
        foreach ($this->links->toArray() as $link) {
            if ($list === null || $link->isUnder($list->getRoot())) {
                $this->links->removeElement($link);
            }
        }

        return $this;
    }

    /** @return Collection<int, ListItemProfile> this item's own plain-profile associations (not the inherited ones) */
    public function getProfileAssociations(): Collection
    {
        return $this->profileAssociations;
    }

    /** Whether this item has associations of its own: links or plain profiles. */
    public function isAssociated(): bool
    {
        return !$this->links->isEmpty() || !$this->profileAssociations->isEmpty();
    }

    /**
     * The shorthand of «associated with this profile»: for a plain profile ($subprofile left null), that profile; for a profile with a
     * list, an element of that list — one of its leaves, or a node above some of them — which is then what the item is linked to
     * (addLink()), declaring that list associated with this one if it was not. The profile is only checked: who owes the item comes
     * out of the element, whatever profile uses that list (ListItemOwners).
     */
    public function addProfileAssociation(SpecificProfile $profile, ?ListItem $subprofile = null): static
    {
        if ($profile->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A list item cannot be associated with a profile from another centre.');
        }
        $list = $profile->getListItem();
        if ($subprofile === null) {
            if ($list !== null) {
                throw new \LogicException('A list-associated profile must be associated through one of its subprofiles.');
            }
            if (!$this->profileAssociations->exists(static fn (int $i, ListItemProfile $a): bool => $a->getSpecificProfile() === $profile)) {
                $this->profileAssociations->add(new ListItemProfile($this, $profile));
            }

            return $this;
        }
        if ($list === null || !$subprofile->isUnder($list)) {
            throw new \LogicException('The subprofile is not an element of the profile\'s list.');
        }

        return $this->addLinkedList($subprofile)->addLink($subprofile);
    }

    public function removeProfileAssociation(ListItemProfile $association): static
    {
        $this->profileAssociations->removeElement($association);

        return $this;
    }

    /** Removes every association of this item's own: links and plain profiles. */
    public function clearAssociations(): static
    {
        $this->links->clear();
        $this->profileAssociations->clear();

        return $this;
    }

    /** @deprecated the same as clearAssociations(), kept for the callers that read better with it */
    public function clearProfileAssociations(): static
    {
        return $this->clearAssociations();
    }

    /**
     * Replaces every association with this single one — or with none, for a null profile (see addProfileAssociation()).
     */
    public function setAssociation(?SpecificProfile $profile, ?ListItem $subprofile = null): static
    {
        if ($profile === null && $subprofile !== null) {
            throw new \LogicException('A subprofile cannot be set without its profile.');
        }

        $this->clearAssociations();
        if ($profile !== null) {
            $this->addProfileAssociation($profile, $subprofile);
        }

        return $this;
    }

    /** @return list<ListItemAssociation> this item's own associations: its links first, then its plain profiles */
    public function getAssociations(): array
    {
        $result = [];
        foreach ($this->links as $link) {
            $result[] = ListItemAssociation::link($link);
        }
        foreach ($this->profileAssociations as $association) {
            $result[] = ListItemAssociation::profile($association->getSpecificProfile());
        }

        return $result;
    }

    /**
     * The associations that apply to this item: its own plus those of every ancestor (they
     * accumulate; nothing is hidden by an association of its own), own ones first, without repeating
     * one (same element, same profile).
     *
     * @return list<ListItemAssociation>
     */
    public function getEffectiveAssociations(): array
    {
        $result = [];
        $keys   = [];
        foreach ($this->selfAndAncestors() as $node) {
            foreach ($node->getAssociations() as $association) {
                $key = $association->key();
                if (!isset($keys[$key])) {
                    $keys[$key] = true;
                    $result[]   = $association;
                }
            }
        }

        return $result;
    }

    /**
     * The ids of the leaves of $list that this item's associations (own and inherited) cover — an intermediate node standing for every
     * leaf under it. $leafIdsUnder gives the leaves under a node (ListItemRepository::findLeafIdsUnder(), cached per request).
     *
     * @param callable(self): list<string> $leafIdsUnder
     *
     * @return array<string, true>
     */
    public function linkedLeafIdsIn(self $list, callable $leafIdsUnder): array
    {
        $root = $list->getRoot();
        $ids  = [];
        foreach ($this->getEffectiveAssociations() as $association) {
            if ($association->linked !== null && $association->linked->isUnder($root)) {
                foreach ($leafIdsUnder($association->linked) as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * What this item inherits: pairs of the ancestor it comes from and the association, closest
     * ancestor first, leaving out what the item already has as its own (and what a closer ancestor
     * already contributed).
     *
     * @return list<array{item: self, association: ListItemAssociation}>
     */
    public function getInheritedAssociations(): array
    {
        $keys = [];
        foreach ($this->getAssociations() as $association) {
            $keys[$association->key()] = true;
        }

        $result = [];
        foreach ($this->selfAndAncestors() as $node) {
            if ($node === $this) {
                continue;
            }
            foreach ($node->getAssociations() as $association) {
                $key = $association->key();
                if (!isset($keys[$key])) {
                    $keys[$key] = true;
                    $result[]   = ['item' => $node, 'association' => $association];
                }
            }
        }

        return $result;
    }

    /**
     * The closest ancestor association that makes $own (one of this item's own) redundant: the same one — `exact` — or a link to a
     * node above $own's element in the same list, which is broader: then the own one narrows nothing and can go.
     *
     * @return array{item: self, association: ListItemAssociation, exact: bool}|null
     */
    public function getAncestorCovering(ListItemAssociation $own): ?array
    {
        $ownKey = $own->key();

        foreach ($this->selfAndAncestors() as $node) {
            if ($node === $this) {
                continue;
            }
            foreach ($node->getAssociations() as $association) {
                if ($association->key() === $ownKey) {
                    return ['item' => $node, 'association' => $association, 'exact' => true];
                }
                if ($association->linked !== null && $own->linked !== null && $own->linked->isUnder($association->linked)) {
                    return ['item' => $node, 'association' => $association, 'exact' => false];
                }
            }
        }

        return null;
    }

    /** True when this item inherits some association from an ancestor that it does not have as its own. */
    public function hasInheritedAssociation(): bool
    {
        return $this->getInheritedAssociations() !== [];
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
