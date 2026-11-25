<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Which activity the rows of a table of deliveries come from («Datos de otras entregas»): the model of an activity has a table that
 * shows a row per delivery of another activity (FormDefinition, mode `delivered`), and each activity that uses that model says,
 * here, which activity that is — one row per table (`blockKey`, the table's key). Deleting either activity removes the row.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_activity_delivery_source', columns: ['activity_id', 'block_key'])]
class ActivityDeliverySource
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'deliverySources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\Column(length: 36)]
    private string $blockKey;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $sourceActivity;

    public function __construct(Activity $activity, string $blockKey, Activity $sourceActivity)
    {
        $this->activity       = $activity;
        $this->blockKey       = $blockKey;
        $this->sourceActivity = $sourceActivity;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getBlockKey(): string
    {
        return $this->blockKey;
    }

    public function getSourceActivity(): Activity
    {
        return $this->sourceActivity;
    }

    public function setSourceActivity(Activity $sourceActivity): static
    {
        $this->sourceActivity = $sourceActivity;

        return $this;
    }
}
