<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormFieldChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * One change of one value of a draft: who changed what, when, from what. Written when a draft is saved, for the history of a field
 * (and to be able to put an earlier value back). A value is told by its block, the copy it is in (`path`) and, in a table, its row
 * and column.
 */
#[ORM\Entity(repositoryClass: FormFieldChangeRepository::class)]
#[ORM\Index(name: 'idx_form_field_change_block', columns: ['submission_id', 'block_key'])]
class FormFieldChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormSubmission $submission;

    #[ORM\Column(length: 40)]
    private string $blockKey;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $rowKey;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $columnKey;

    /** The value as JSON text (a value can be a number, a text, a list…); null when there was none. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $changedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(FormSubmission $submission, string $blockKey, string $path, ?string $rowKey, ?string $columnKey, ?string $oldValue, ?string $newValue, ?Teacher $by)
    {
        $this->submission = $submission;
        $this->blockKey   = $blockKey;
        $this->path       = $path;
        $this->rowKey     = $rowKey;
        $this->columnKey  = $columnKey;
        $this->oldValue   = $oldValue;
        $this->newValue   = $newValue;
        $this->changedBy  = $by;
        $this->changedAt  = now();
    }

    /** The same person is still typing in the same value: the change grows instead of a new one being written per keystroke. */
    public function amend(?string $newValue): static
    {
        $this->newValue  = $newValue;
        $this->changedAt = now();

        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBlockKey(): string
    {
        return $this->blockKey;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRowKey(): ?string
    {
        return $this->rowKey;
    }

    public function getColumnKey(): ?string
    {
        return $this->columnKey;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getChangedBy(): ?Teacher
    {
        return $this->changedBy;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
