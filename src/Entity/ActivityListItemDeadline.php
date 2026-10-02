<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A deadline override for one leaf of a list-backed activity (Activity::$listItem): when a leaf has
 * a row here, its own submissions/completion use this day/month pair instead of the activity's own
 * ($startDay/$startMonth/$endDay/$endMonth) — see ActivityDeadlineChecker. A leaf with no row here
 * simply uses the activity's default, so adding this table required no backfill: every existing
 * activity keeps behaving exactly as before until an override is explicitly added.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_activity_list_item_deadline', columns: ['activity_id', 'list_item_id'])]
class ActivityListItemDeadline
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'listItemDeadlines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ListItem $listItem;

    #[ORM\Column]
    private int $startDay;

    #[ORM\Column]
    private int $startMonth;

    #[ORM\Column]
    private int $endDay;

    #[ORM\Column]
    private int $endMonth;

    public function __construct(Activity $activity, ListItem $listItem, int $startDay, int $startMonth, int $endDay, int $endMonth)
    {
        $this->activity   = $activity;
        $this->listItem   = $listItem;
        $this->startDay   = $startDay;
        $this->startMonth = $startMonth;
        $this->endDay     = $endDay;
        $this->endMonth   = $endMonth;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getListItem(): ListItem
    {
        return $this->listItem;
    }

    public function getStartDay(): int
    {
        return $this->startDay;
    }

    public function getStartMonth(): int
    {
        return $this->startMonth;
    }

    public function getEndDay(): int
    {
        return $this->endDay;
    }

    public function getEndMonth(): int
    {
        return $this->endMonth;
    }

    public function setRange(int $startDay, int $startMonth, int $endDay, int $endMonth): static
    {
        $this->startDay   = $startDay;
        $this->startMonth = $startMonth;
        $this->endDay     = $endDay;
        $this->endMonth   = $endMonth;

        return $this;
    }
}
