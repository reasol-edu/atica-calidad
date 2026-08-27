<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Associates a document section with a specific profile or, when a list item is given, one
 * subperfil of a list-associated profile.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_document_section_profile', columns: ['document_section_id', 'specific_profile_id', 'list_item_id'])]
class DocumentSectionProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'profileRestrictions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentSection $documentSection;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpecificProfile $specificProfile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ListItem $listItem;

    public function __construct(DocumentSection $documentSection, SpecificProfile $specificProfile, ?ListItem $listItem)
    {
        $this->documentSection = $documentSection;
        $this->specificProfile = $specificProfile;
        $this->listItem        = $listItem;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocumentSection(): DocumentSection
    {
        return $this->documentSection;
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
