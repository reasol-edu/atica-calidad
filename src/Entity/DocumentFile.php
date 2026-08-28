<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Binary blob store for document revisions, deduplicated by content hash — same pattern as
 * SettingFile (src/Entity/SettingFile.php), kept as a separate entity so the settings domain and
 * the quality-documents domain stay decoupled.
 */
#[ORM\Entity(repositoryClass: DocumentFileRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_document_file_hash', columns: ['hash'])]
class DocumentFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $hash;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB)]
    private $content;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column]
    private int $size;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $hash, string $content, string $mimeType, string $originalFilename, int $size)
    {
        $this->hash             = $hash;
        $this->content          = $content;
        $this->mimeType         = $mimeType;
        $this->originalFilename = $originalFilename;
        $this->size             = $size;
        $this->createdAt        = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getContent(): string
    {
        return is_resource($this->content) ? (string) stream_get_contents($this->content) : (string) $this->content;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
