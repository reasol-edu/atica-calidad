<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormModelRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The frozen definition of one DocumentRevision of a model. Immutable: a change is always a new revision, and a filled
 * document is only ever read with the definition of the revision it was filled with.
 */
#[ORM\Entity(repositoryClass: FormModelRevisionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_form_model_revision_revision', columns: ['revision_id'])]
class FormModelRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormModel $model;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentRevision $revision;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $definition;

    /** Code of the model when it was frozen (it can change later). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $code;

    /** @param array<string, mixed> $definition */
    public function __construct(FormModel $model, DocumentRevision $revision, array $definition)
    {
        $this->model      = $model;
        $this->revision   = $revision;
        $this->definition = $definition;
        $this->code       = $model->getCode();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getModel(): FormModel
    {
        return $this->model;
    }

    public function getRevision(): DocumentRevision
    {
        return $this->revision;
    }

    /** @return array<string, mixed> */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }
}
