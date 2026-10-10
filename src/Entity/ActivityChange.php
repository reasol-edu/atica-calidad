<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One entry of an activity's history: who created, duplicated or edited it, when, and — for an
 * edit — each setting that changed with its old and new value (as readable text; see
 * ActivityChangeRecorder). Kept with the activity, so it also survives a trip to the trash.
 */
#[ORM\Entity(repositoryClass: ActivityChangeRepository::class)]
#[ORM\Table(name: 'activity_change')]
#[ORM\Index(columns: ['activity_id', 'created_at'], name: 'idx_activity_change_activity_created')]
class ActivityChange
{
    public const string CREATED    = 'created';
    public const string DUPLICATED = 'duplicated';
    public const string UPDATED    = 'updated';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Activity $activity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $author;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type;

    /** @var list<array{field: string, from: string, to: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    /** @param list<array{field: string, from: string, to: string}> $changes */
    public function __construct(Activity $activity, ?Teacher $author, \DateTimeImmutable $createdAt, string $type, array $changes = [])
    {
        $this->activity  = $activity;
        $this->author    = $author;
        $this->createdAt = $createdAt;
        $this->type      = $type;
        $this->changes   = $changes;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getAuthor(): ?Teacher
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return list<array{field: string, from: string, to: string}> */
    public function getChanges(): array
    {
        return $this->changes;
    }
}
