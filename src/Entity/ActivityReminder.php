<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A reminder sent by hand about an activity to one teacher who hasn't completed it — who, when,
 * by whom and with what message. It's the record behind "Avisado el 03/10" in the tracking
 * panel and the history of an activity's reminders; the daily automatic digest isn't recorded here.
 */
#[ORM\Entity(repositoryClass: ActivityReminderRepository::class)]
#[ORM\Table(name: 'activity_reminder')]
#[ORM\Index(columns: ['activity_id', 'sent_at'], name: 'idx_activity_reminder_activity_sent')]
class ActivityReminder
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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $sentBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    /** False when the email couldn't go out (the teacher has emails off, no address, or the transport failed). */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $delivered;

    public function __construct(Activity $activity, Teacher $recipient, ?Teacher $sentBy, \DateTimeImmutable $sentAt, ?string $message, bool $delivered)
    {
        $this->activity  = $activity;
        $this->recipient = $recipient;
        $this->sentBy    = $sentBy;
        $this->sentAt    = $sentAt;
        $this->message   = $message;
        $this->delivered = $delivered;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
    }

    public function getRecipient(): Teacher
    {
        return $this->recipient;
    }

    public function getSentBy(): ?Teacher
    {
        return $this->sentBy;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function isDelivered(): bool
    {
        return $this->delivered;
    }
}
