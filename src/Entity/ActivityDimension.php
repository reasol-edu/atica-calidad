<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A list an activity's submissions are multiplied by («una entrega por materia y evaluación»): its leaves are the values, and every
 * submission is one combination of an element of the activity with one value of each dimension. Associated — the activity's list declares
 * the dimension's list associated (ListItem::getLinkedLists()) — each element takes only the values it is linked to; free, every value.
 * At most one dimension of an activity is `dated`: each of its values has its own date range (an ActivityListItemDeadline on that value),
 * and each is its own occurrence in the calendar and the tasks. See ActivitySubmissionSlotBuilder.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_activity_dimension', columns: ['activity_id', 'list_id'])]
class ActivityDimension
{
    /** At most this many dimensions per activity: the key of a submission must fit its column. */
    public const int MAX = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'dimensions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    /** Any node of the catalogue: its leaves are the values (usually a root, «Evaluación», but «Evaluación › FP» works too). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'list_id', nullable: false, onDelete: 'CASCADE')]
    private ListItem $list;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(options: ['default' => false])]
    private bool $dated;

    public function __construct(Activity $activity, ListItem $list, int $position, bool $dated = false)
    {
        $this->activity = $activity;
        $this->list     = $list;
        $this->position = $position;
        $this->dated    = $dated;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getList(): ListItem
    {
        return $this->list;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isDated(): bool
    {
        return $this->dated;
    }

    public function setDated(bool $dated): static
    {
        $this->dated = $dated;

        return $this;
    }
}
