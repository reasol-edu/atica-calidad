<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentReadAcknowledgementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A teacher's "I've read it" for one version of a document in a folder that requires it
 * (Folder::requiresReadAcknowledgement()). Tied to the revision, not the document: a new version
 * in force has to be read — and acknowledged — again. See ReadAcknowledgementService.
 */
#[ORM\Entity(repositoryClass: DocumentReadAcknowledgementRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_document_read_ack_revision_teacher', columns: ['revision_id', 'teacher_id'])]
class DocumentReadAcknowledgement
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DocumentRevision $revision,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Teacher $teacher,
        #[ORM\Column]
        private \DateTimeImmutable $acknowledgedAt,
    ) {}

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRevision(): DocumentRevision
    {
        return $this->revision;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getAcknowledgedAt(): \DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }
}
