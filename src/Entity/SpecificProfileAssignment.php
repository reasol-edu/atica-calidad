<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SpecificProfileAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One teacher assigned to a profile — either directly ($listItem === null,
 * when the profile has no associated list) or to one specific leaf of the
 * profile's associated list element ($listItem is that leaf, a "virtual
 * subperfil"). A single mechanism covers both cases.
 */
#[ORM\Entity(repositoryClass: SpecificProfileAssignmentRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_specific_profile_assignment', columns: ['specific_profile_id', 'list_item_id', 'teacher_id'])]
class SpecificProfileAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SpecificProfile $specificProfile;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?ListItem $listItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $teacher;

    public function __construct(SpecificProfile $specificProfile, ?ListItem $listItem, Teacher $teacher)
    {
        $this->specificProfile = $specificProfile;
        $this->listItem        = $listItem;
        $this->teacher         = $teacher;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSpecificProfile(): SpecificProfile
    {
        return $this->specificProfile;
    }

    public function getListItem(): ?ListItem
    {
        return $this->listItem;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }
}
