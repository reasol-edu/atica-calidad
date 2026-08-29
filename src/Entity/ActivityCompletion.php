<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityCompletionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * A manual "marked as completed" record for one owner of an activity's submission scope — either a
 * single teacher (Activity::submissionScope Individual) or a profile/subperfil shared by everyone
 * holding it (ByProfile), never both at once. Only ever created for manual activities: an
 * auto-complete activity's completion state is computed on the fly instead (see
 * ActivitySubmissionSlotBuilder), nothing to persist.
 */
#[ORM\Entity(repositoryClass: ActivityCompletionRepository::class)]
class ActivityCompletion
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Teacher $teacher = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpecificProfile $profile = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ListItem $listItem = null;

    /** Who actually pressed the button — may differ from $teacher when the scope is ByProfile. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $completedBy;

    #[ORM\Column]
    private \DateTimeImmutable $completedAt;

    public function __construct(Activity $activity, ?Teacher $teacher, ?SpecificProfile $profile, ?ListItem $listItem, Teacher $completedBy)
    {
        $this->activity    = $activity;
        $this->teacher     = $teacher;
        $this->profile     = $profile;
        $this->listItem    = $listItem;
        $this->completedBy = $completedBy;
        $this->completedAt = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getTeacher(): ?Teacher
    {
        return $this->teacher;
    }

    public function getProfile(): ?SpecificProfile
    {
        return $this->profile;
    }

    public function getListItem(): ?ListItem
    {
        return $this->listItem;
    }

    public function getCompletedBy(): Teacher
    {
        return $this->completedBy;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}
