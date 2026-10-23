<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One profile/subprofile a list item is associated with — a loose cross-reference unrelated to the
 * list's own hierarchy (e.g. a "Matemáticas" element with the "Jefatura de departamento"
 * subprofile it falls under). An element can have several. $subprofile mirrors the
 * (profile, listItem) pair used everywhere else in the app (see ProfileAssignmentRow): null for a
 * plain profile, otherwise a leaf of the profile's own list — or an intermediate node of it,
 * standing for every leaf under it (ActivitySubmissionSlotBuilder expands it into one submission
 * per leaf). Deleting the profile, the element or the subprofile removes the row.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_list_item_profile', columns: ['list_item_id', 'specific_profile_id', 'subprofile_id'])]
class ListItemProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'profileAssociations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ListItem $listItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpecificProfile $specificProfile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subprofile_id', nullable: true, onDelete: 'CASCADE')]
    private ?ListItem $subprofile;

    public function __construct(ListItem $listItem, SpecificProfile $specificProfile, ?ListItem $subprofile)
    {
        $this->listItem        = $listItem;
        $this->specificProfile = $specificProfile;
        $this->subprofile      = $subprofile;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getListItem(): ListItem
    {
        return $this->listItem;
    }

    public function getSpecificProfile(): SpecificProfile
    {
        return $this->specificProfile;
    }

    /** The subprofile (leaf, or intermediate node) of the profile's list — null for a plain profile. */
    public function getSubprofile(): ?ListItem
    {
        return $this->subprofile;
    }
}
