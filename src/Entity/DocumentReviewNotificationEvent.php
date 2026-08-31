<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentReviewNotificationEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * One document-review event (a revision sent for review, approved, or rejected) still owed to
 * whichever recipients have that event's notification mode set to "daily_digest" — created by
 * DocumentReviewNotifier/DocumentReviewOutcomeNotifier alongside any immediate ("individual")
 * emails they send for the same event, and deleted in bulk by SendDocumentReviewDigestHandler once
 * it has built that day's digest for every teacher in the centre. A row's mere presence in the
 * queue *is* "new since the last digest" — there's no separate timestamp-based cutoff to compute.
 */
#[ORM\Entity(repositoryClass: DocumentReviewNotificationEventRepository::class)]
class DocumentReviewNotificationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentRevision $documentRevision;

    #[ORM\Column(enumType: DocumentReviewNotificationKind::class)]
    private DocumentReviewNotificationKind $kind;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(DocumentRevision $documentRevision, DocumentReviewNotificationKind $kind)
    {
        $this->documentRevision = $documentRevision;
        $this->kind             = $kind;
        $this->createdAt        = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocumentRevision(): DocumentRevision
    {
        return $this->documentRevision;
    }

    public function getKind(): DocumentReviewNotificationKind
    {
        return $this->kind;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
