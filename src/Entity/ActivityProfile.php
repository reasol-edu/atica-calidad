<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One profile/subprofile a manual (folder-less) activity is restricted to — either the profile
 * directly ($listItem === null) or one specific leaf of the profile's associated list element (a
 * "virtual subprofile"). Mirrors SpecificProfileAssignment's shape so the two can be matched
 * directly: a restricted activity applies to a teacher who holds a SpecificProfileAssignment for
 * the same (specificProfile, listItem) pair as one of the activity's ActivityProfile rows — see
 * ActivityCompletionChecker::isApplicableToTeacher(). Meaningless for a folder-backed activity,
 * whose ownership already comes from the folder's own upload profiles.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_activity_profile', columns: ['activity_id', 'specific_profile_id', 'list_item_id'])]
class ActivityProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'profileRestrictions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpecificProfile $specificProfile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ListItem $listItem;

    public function __construct(Activity $activity, SpecificProfile $specificProfile, ?ListItem $listItem)
    {
        $this->activity        = $activity;
        $this->specificProfile = $specificProfile;
        $this->listItem        = $listItem;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
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
