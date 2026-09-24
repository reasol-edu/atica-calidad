<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One line of a Finding's history — a step of its workflow, something about its actions or
 * attachments, or a comment — with who did it and when. Written by the workflow's event listener
 * and FindingService, never edited afterwards: it's the record an audit asks for.
 */
#[ORM\Entity]
class FindingTimelineEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'timeline')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(enumType: FindingEventKind::class)]
    private FindingEventKind $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $actor;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** A comment, a reason, notes… as written by the actor. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text;

    /**
     * What the entry is about, for its label: e.g. the action's description.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data;

    /** @param array<string, string>|null $data */
    public function __construct(Finding $finding, FindingEventKind $kind, ?Teacher $actor, \DateTimeImmutable $occurredAt, ?string $text = null, ?array $data = null)
    {
        $this->finding    = $finding;
        $this->kind       = $kind;
        $this->actor      = $actor;
        $this->occurredAt = $occurredAt;
        $this->text       = $text;
        $this->data       = $data;
        $finding->getTimeline()->add($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFinding(): Finding
    {
        return $this->finding;
    }

    public function getKind(): FindingEventKind
    {
        return $this->kind;
    }

    public function getActor(): ?Teacher
    {
        return $this->actor;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /** @return array<string, string> */
    public function getData(): array
    {
        return $this->data ?? [];
    }
}
