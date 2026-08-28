<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Associates a folder with a specific profile or, when a list item is given, one subperfil of a
 * list-associated profile — one of the folder's "upload" profiles: can upload documents, but only
 * tagged with a profile the uploader themselves holds (unlike a responsible, who can pick any).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_folder_upload_profile', columns: ['folder_id', 'specific_profile_id', 'list_item_id'])]
class FolderUploadProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'uploadProfiles')]
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
