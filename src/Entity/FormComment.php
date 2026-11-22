<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * A comment of a reviewer on one part of a delivered document (a section or a block, by key — and the copy it is in, by path), so
 * a rejection says where the problem is and not only that there is one.
 */
#[ORM\Entity(repositoryClass: FormCommentRepository::class)]
#[ORM\Index(name: 'idx_form_comment_revision', columns: ['revision_id'])]
class FormComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FormSubmissionRevision $revision;

    /** The key of the section or block the comment is about. */
    #[ORM\Column(length: 40)]
    private string $anchorKey;

    /** Where it is among the copies of repeating sections (FormWalker's path); '' when it is not in one. */
    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(FormSubmissionRevision $revision, string $anchorKey, string $path, ?Teacher $author, string $body)
    {
        $this->revision  = $revision;
        $this->anchorKey = $anchorKey;
        $this->path      = $path;
        $this->author    = $author;
        $this->body      = $body;
        $this->createdAt = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRevision(): FormSubmissionRevision
    {
        return $this->revision;
    }

    public function getAnchorKey(): string
    {
        return $this->anchorKey;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getAuthor(): ?Teacher
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    public function toggleResolved(): static
    {
        $this->resolvedAt = $this->resolvedAt === null ? now() : null;

        return $this;
    }
}
