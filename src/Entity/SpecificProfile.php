<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SpecificProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A centre-defined profile (e.g. "Tutor/a"). Optionally associated with a
 * list element: when it is, every leaf descendant of that element is a
 * "virtual subperfil" (e.g. "Tutor/a 1º ESO-A") with its own independent
 * teacher assignments; when it isn't, teachers are assigned to the profile
 * directly. See SpecificProfileAssignment for how both cases share one
 * storage mechanism.
 */
#[ORM\Entity(repositoryClass: SpecificProfileRepository::class)]
class SpecificProfile
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ListItem $listItem = null;

    /** @var Collection<int, SpecificProfileAssignment> */
    #[ORM\OneToMany(targetEntity: SpecificProfileAssignment::class, mappedBy: 'specificProfile', cascade: ['persist'], orphanRemoval: true)]
    private Collection $assignments;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
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
        return $this->educationalCentre;
    }

    public function setEducationalCentre(EducationalCentre $educationalCentre): static
    {
        $this->educationalCentre = $educationalCentre;

        return $this;
    }

    public function getListItem(): ?ListItem
    {
        return $this->listItem;
    }

    public function setListItem(?ListItem $listItem): static
    {
        if ($listItem !== null && $listItem->getEducationalCentre() !== $this->educationalCentre) {
            throw new \LogicException('A specific profile cannot be associated with a list item from another centre.');
        }

        $this->listItem = $listItem;

        return $this;
    }

    public function isListAssociated(): bool
    {
        return $this->listItem !== null;
    }

    /** @return Collection<int, SpecificProfileAssignment> */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function hasAssignment(Teacher $teacher, ?ListItem $listItem): bool
    {
        return $this->assignments->exists(
            static fn (int $i, SpecificProfileAssignment $a): bool =>
                $a->getTeacher() === $teacher && $a->getListItem() === $listItem
        );
    }

    public function addAssignment(Teacher $teacher, ?ListItem $listItem = null): static
    {
        if (!$this->hasAssignment($teacher, $listItem)) {
            $this->assignments->add(new SpecificProfileAssignment($this, $listItem, $teacher));
        }

        return $this;
    }

    public function removeAssignment(SpecificProfileAssignment $assignment): static
    {
        $this->assignments->removeElement($assignment);

        return $this;
    }
}
