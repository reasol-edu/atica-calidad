<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A file attached to a field «Adjuntos» of a filled document (an act, a photo, a spreadsheet as evidence). It belongs to the
 * submission (the document of one slot), not to one revision: the data of each delivered revision lists the attachments it had, so
 * a correction that removes one leaves it in the earlier revision. Never deleted while the submission exists. The content lives in
 * the shared, deduplicated DocumentFile store — which is why DocumentFileGarbageCollector and the orphan sweep count these too.
 */
#[ORM\Entity(repositoryClass: FormAttachmentRepository::class)]
#[ORM\Index(name: 'idx_form_attachment_submission', columns: ['submission_id'])]
class FormAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormSubmission $submission;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private DocumentFile $file;

    #[ORM\Column(length: 255)]
    private string $filename;

    /** Copied from the file, so listing the attachments never loads their content (the file's entity holds it). */
    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $uploadedBy;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(FormSubmission $submission, DocumentFile $file, string $filename, ?Teacher $uploadedBy, \DateTimeImmutable $uploadedAt)
    {
        $this->submission = $submission;
        $this->file       = $file;
        $this->filename   = mb_substr($filename, 0, 255);
        $this->mimeType   = mb_substr($file->getMimeType(), 0, 100);
        $this->size       = $file->getSize();
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = $uploadedAt;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubmission(): FormSubmission
    {
        return $this->submission;
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

    /**
     * What the data of a field keeps of it (the server rewrites it from here on every save: a browser cannot rename or resize one).
     *
     * @return array{id: string, name: string, size: int, mime: string}
     */
    public function toValue(): array
    {
        return ['id' => $this->id->toRfc4122(), 'name' => $this->filename, 'size' => $this->size, 'mime' => $this->mimeType];
    }
}
