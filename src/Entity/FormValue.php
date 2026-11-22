<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One scalar value of a delivered document, derived from its frozen data and definition (never the source of truth: it is rebuilt
 * with `app:forms:reindex`). What indicators and the data explorer read, so they never load whole documents. A value is told by its
 * field (or table column) key, the copy it is in (`path`) and, in a table, its row.
 */
#[ORM\Entity(repositoryClass: FormValueRepository::class)]
#[ORM\Index(name: 'idx_form_value_field', columns: ['field_key', 'submission_revision_id'])]
class FormValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormSubmissionRevision $submissionRevision;

    #[ORM\Column(length: 36)]
    private string $fieldKey;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $rowKey;

    /** The number, for number-like values (and 1/0 for yes/no). */
    #[ORM\Column(nullable: true)]
    private ?float $num;

    public const int TEXT_LENGTH = 1000;

    /** The text, cut to TEXT_LENGTH characters (choices: the key of the option; a multiple choice has one row per option chosen). */
    #[ORM\Column(length: self::TEXT_LENGTH, nullable: true)]
    private ?string $text;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $flag;

    public function __construct(FormSubmissionRevision $submissionRevision, string $fieldKey, string $path, ?string $rowKey, ?float $num, ?string $text, ?bool $flag)
    {
        $this->submissionRevision = $submissionRevision;
        $this->fieldKey           = $fieldKey;
        $this->path               = mb_substr($path, 0, 255);
        $this->rowKey             = $rowKey;
        $this->num                = $num;
        $this->text               = $text === null ? null : mb_substr($text, 0, self::TEXT_LENGTH);
        $this->flag               = $flag;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubmissionRevision(): FormSubmissionRevision
    {
        return $this->submissionRevision;
    }

    public function getFieldKey(): string
    {
        return $this->fieldKey;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRowKey(): ?string
    {
        return $this->rowKey;
    }

    public function getNum(): ?float
    {
        return $this->num;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getFlag(): ?bool
    {
        return $this->flag;
    }
}
