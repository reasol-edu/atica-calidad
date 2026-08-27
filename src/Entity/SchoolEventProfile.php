<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One profile/subperfil a calendar event is restricted to — either the profile directly
 * ($listItem === null) or one specific leaf of the profile's associated list element (a "virtual
 * subperfil"). Mirrors SpecificProfileAssignment's shape so the two can be matched directly:
 * a restricted event is visible to a teacher who has a SpecificProfileAssignment for the same
 * (specificProfile, listItem) pair as one of the event's SchoolEventProfile rows.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_school_event_profile', columns: ['school_event_id', 'specific_profile_id', 'list_item_id'])]
class SchoolEventProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'profileRestrictions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolEvent $schoolEvent;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpecificProfile $specificProfile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ListItem $listItem;

    public function __construct(SchoolEvent $schoolEvent, SpecificProfile $specificProfile, ?ListItem $listItem)
    {
        $this->schoolEvent     = $schoolEvent;
        $this->specificProfile = $specificProfile;
        $this->listItem        = $listItem;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSchoolEvent(): SchoolEvent
    {
        return $this->schoolEvent;
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
