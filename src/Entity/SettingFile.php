<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Almacén genérico de ficheros binarios, deduplicado por hash de contenido.
 * Referenciado desde GlobalSettingValue/CentreSettingValue/TeacherSettingValue
 * para dar soporte a ajustes de tipo fichero (plantillas PDF, logotipos, etc.)
 * a cualquier nivel jerárquico.
 */
#[ORM\Entity(repositoryClass: SettingFileRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_setting_file_hash', columns: ['hash'])]
class SettingFile
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

    #[ORM\Column]
    private int $size;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $hash, string $content, string $mimeType, int $size)
    {
        $this->hash      = $hash;
        $this->content   = $content;
        $this->mimeType  = $mimeType;
        $this->size      = $size;
        $this->createdAt = now();
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

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
