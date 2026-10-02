<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One of a manual (folder-less) activity's "responsible" profiles: a teacher holding it can see
 * the activity's completion stats and, same as a responsable de calidad/admin, mark or unmark
 * another teacher's completion — see ActivityCompletionChecker::isResponsibleFor(). Meaningless
 * for a folder-backed activity, whose management already comes from the folder's own
 * FolderResponsibleProfile rows; mirrors that entity's shape.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_activity_responsible_profile', columns: ['activity_id', 'specific_profile_id', 'list_item_id'])]
class ActivityResponsibleProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'responsibleProfiles')]
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
