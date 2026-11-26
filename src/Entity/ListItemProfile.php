<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A plain profile (one without a list of its own, e.g. «Orientación») a list item is associated with — a loose cross-reference
 * unrelated to the list's own hierarchy. A profile that has a list is never associated like this: the item is associated with an
 * element of that list instead (ListItem::addLink(), e.g. «Departamento › Informática»), and who owes the item comes out of it
 * (ListItemOwners). Deleting the profile or the element removes the row.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_list_item_profile', columns: ['list_item_id', 'specific_profile_id'])]
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

    public function __construct(ListItem $listItem, SpecificProfile $specificProfile)
    {
        $this->listItem        = $listItem;
        $this->specificProfile = $specificProfile;
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
}
