<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QualityAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A file attached to a Finding (a photo of the problem, a report) or to one of its
 * ImprovementActions (the evidence it was done). The content lives in the shared, deduplicated
 * DocumentFile store — which is why DocumentFileGarbageCollector and the orphan sweep also count
 * these before deleting a file.
 */
#[ORM\Entity(repositoryClass: QualityAttachmentRepository::class)]
class QualityAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Finding $finding = null;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ImprovementAction $action = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private DocumentFile $file;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $uploadedBy;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    private function __construct(DocumentFile $file, string $filename, ?Teacher $uploadedBy, \DateTimeImmutable $uploadedAt)
    {
        $this->file       = $file;
        $this->filename   = $filename;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = $uploadedAt;
    }

    public static function forFinding(Finding $finding, DocumentFile $file, string $filename, ?Teacher $uploadedBy, \DateTimeImmutable $uploadedAt): self
    {
        $attachment          = new self($file, $filename, $uploadedBy, $uploadedAt);
        $attachment->finding = $finding;
        $finding->getAttachments()->add($attachment);

        return $attachment;
    }

    public static function forAction(ImprovementAction $action, DocumentFile $file, string $filename, ?Teacher $uploadedBy, \DateTimeImmutable $uploadedAt): self
    {
        $attachment         = new self($file, $filename, $uploadedBy, $uploadedAt);
        $attachment->action = $action;
        $action->getAttachments()->add($attachment);

        return $attachment;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFinding(): ?Finding
    {
        return $this->finding;
    }

    public function getAction(): ?ImprovementAction
    {
        return $this->action;
    }

    /** The finding it belongs to, directly or through its action. */
    public function getOwningFinding(): ?Finding
    {
        return $this->finding ?? $this->action?->getFinding();
    }

    public function getFile(): DocumentFile
    {
        return $this->file;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getUploadedBy(): ?Teacher
    {
        return $this->uploadedBy;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
