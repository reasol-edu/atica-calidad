<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Associates a folder with a specific profile or, when a list item is given, one subprofile of a
 * list-associated profile — one of the folder's "visibility" profiles: if the folder has any, only
 * teachers holding one of them (plus admins/quality managers/internal auditors) can see it at all.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_folder_visibility_profile', columns: ['folder_id', 'specific_profile_id', 'list_item_id'])]
class FolderVisibilityProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'visibilityProfiles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Folder $folder;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpecificProfile $specificProfile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ListItem $listItem;

    public function __construct(Folder $folder, SpecificProfile $specificProfile, ?ListItem $listItem)
    {
        $this->folder          = $folder;
        $this->specificProfile = $specificProfile;
        $this->listItem        = $listItem;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFolder(): Folder
    {
        return $this->folder;
    }

    public function getSpecificProfile(): SpecificProfile
    {
        return $this->specificProfile;
    }

    public function getListItem(): ?ListItem
    {
        return $this->listItem;
    }
}
