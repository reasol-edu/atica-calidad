<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormSubmissionRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The data of one delivered DocumentRevision of a filled document. Immutable: it is read only with the definition of its own
 * model revision, and a correction is a new revision.
 */
#[ORM\Entity(repositoryClass: FormSubmissionRevisionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_form_submission_revision_revision', columns: ['revision_id'])]
class FormSubmissionRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormSubmission $submission;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentRevision $revision;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private FormModelRevision $modelRevision;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(FormSubmission $submission, DocumentRevision $revision, FormModelRevision $modelRevision, array $data)
    {
        $this->submission    = $submission;
        $this->revision      = $revision;
        $this->modelRevision = $modelRevision;
        $this->data          = $data;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubmission(): FormSubmission
    {
        return $this->submission;
    }

    public function getRevision(): DocumentRevision
    {
        return $this->revision;
    }

    public function getModelRevision(): FormModelRevision
    {
        return $this->modelRevision;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }
}
