<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormModelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Marks a Document of the tree as a fillable-document MODEL ("modelo"): its content is a definition — a tree of sections —
 * instead of a file. What is being edited lives here as the draft; every revision sent to review freezes a copy as a
 * FormModelRevision (whose DocumentRevision carries the blank PDF, so the tree, the search and the backups keep working).
 * See docs / skills/forms.md.
 */
#[ORM\Entity(repositoryClass: FormModelRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_form_model_document', columns: ['document_id'])]
class FormModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    /** The code printed in the header of the PDF (MD850703). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $code = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $draftDefinition;

    /** Bumped on every save of the draft: two editors cannot overwrite each other unnoticed. */
    #[ORM\Column]
    private int $draftVersion = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $draftUpdatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $draftUpdatedBy = null;

    /** @param array<string, mixed> $definition */
    public function __construct(Document $document, array $definition)
    {
        $this->document        = $document;
        $this->draftDefinition = $definition;
        $this->draftUpdatedAt  = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $code       = $code === null ? null : trim($code);
        $this->code = $code === '' ? null : $code;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getDraftDefinition(): array
    {
        return $this->draftDefinition;
    }

    /** @param array<string, mixed> $definition */
    public function saveDraft(array $definition, ?Teacher $by): static
    {
        $this->draftDefinition = $definition;
        $this->draftUpdatedBy  = $by;
        $this->draftUpdatedAt  = now();
        ++$this->draftVersion;

        return $this;
    }

    public function getDraftVersion(): int
    {
        return $this->draftVersion;
    }

    public function getDraftUpdatedAt(): \DateTimeImmutable
    {
        return $this->draftUpdatedAt;
    }

    public function getDraftUpdatedBy(): ?Teacher
    {
        return $this->draftUpdatedBy;
    }
}
