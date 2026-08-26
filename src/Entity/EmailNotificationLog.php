<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailNotificationLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EmailNotificationLogRepository::class)]
#[ORM\Table(name: 'email_notification_log')]
#[ORM\Index(columns: ['educational_centre_id', 'sent_at'], name: 'idx_enl_centre_sent')]
#[ORM\Index(columns: ['recipient_id'], name: 'idx_enl_recipient')]
#[ORM\Index(columns: ['event_key'], name: 'idx_enl_event')]
class EmailNotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne(targetEntity: Teacher::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $recipient = null;

    #[ORM\Column(length: 200)]
    private string $recipientName;

    #[ORM\Column(length: 50)]
    private string $eventKey;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $success;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    public function __construct(
        EducationalCentre $educationalCentre,
        ?Teacher $recipient,
        string $recipientName,
        string $eventKey,
        string $subject,
        bool $success,
        ?string $errorMessage,
        \DateTimeImmutable $sentAt,
    ) {
        $this->educationalCentre = $educationalCentre;
        $this->recipient         = $recipient;
        $this->recipientName     = $recipientName;
        $this->eventKey          = $eventKey;
        $this->subject           = $subject;
        $this->success           = $success;
        $this->errorMessage      = $errorMessage;
        $this->sentAt            = $sentAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getRecipient(): ?Teacher
    {
        return $this->recipient;
    }

    public function getRecipientName(): string
    {
        return $this->recipientName;
    }

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
