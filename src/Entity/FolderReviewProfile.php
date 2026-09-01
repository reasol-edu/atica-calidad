<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Associates a folder with a specific profile or, when a list item is given, one subprofile of a
 * list-associated profile — one of the folder's "review" profiles: if the folder has any, newly
 * uploaded or replaced revisions stay pending until one of these (or a responsible) approves or
 * rejects them.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_folder_review_profile', columns: ['folder_id', 'specific_profile_id', 'list_item_id'])]
class FolderReviewProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'reviewProfiles')]
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
