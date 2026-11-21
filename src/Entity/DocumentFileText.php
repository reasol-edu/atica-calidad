<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentFileTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The searchable text of one stored file (DocumentFile), read once by the worker. One row per file, not per revision or per
 * document: files are deduplicated by content, so the same file in two documents (even of two centres) is read once, and who
 * may see a result is always decided from the documents that point at the file, never from this table.
 *
 * `text` is what a snippet is cut from and `folded` is the same text lower-cased and without accents, character for
 * character (see TextNormalizer::fold()), which is what the search runs LIKE on, so both columns share offsets. It is
 * written by DocumentFileTextRepository::save() straight through DBAL, never through the entity manager: the indexer
 * runs inside whatever request or worker stored the file and must not flush someone else's unit of work.
 */
#[ORM\Entity(repositoryClass: DocumentFileTextRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_document_file_text_file', columns: ['file_id'])]
#[ORM\Index(name: 'idx_document_file_text_status', columns: ['status'])]
class DocumentFileText
{
    /** Longest text kept per file, in characters (a few hundred pages). The rest of a longer file is not searched. */
    public const int MAX_CHARS = 1_500_000;

    /** MEDIUMTEXT on MySQL/MariaDB (a plain TEXT holds 64 KB there, less than one report); no limit elsewhere. */
    private const int TEXT_LENGTH = 16_777_215;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentFile $file;

    #[ORM\Column(length: 20, enumType: TextIndexStatus::class)]
    private TextIndexStatus $status;

    /** Version of the reading code that produced this row; a newer one reads the file again (DocumentTextIndexer::VERSION). */
    #[ORM\Column]
    private int $extractorVersion;

    #[ORM\Column(type: Types::TEXT, length: self::TEXT_LENGTH, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(type: Types::TEXT, length: self::TEXT_LENGTH, nullable: true)]
    private ?string $folded = null;

    /** Why a file could not be read, short and for the administrator. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    /**
     * Pages already read with OCR: null while OCR has not been tried on this file, 0 when it is queued, and the last page done
     * while it is under way (status OcrPending) or finished. A scan that OCR found nothing in keeps its number, so it is not tried again.
     */
    #[ORM\Column(nullable: true)]
    private ?int $ocrPage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $indexedAt;

    public function __construct(DocumentFile $file, TextIndexStatus $status, int $extractorVersion, \DateTimeImmutable $indexedAt)
    {
        $this->file             = $file;
        $this->status           = $status;
        $this->extractorVersion = $extractorVersion;
        $this->indexedAt        = $indexedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFile(): DocumentFile
    {
        return $this->file;
    }

    public function getStatus(): TextIndexStatus
    {
        return $this->status;
    }

    public function getExtractorVersion(): int
    {
        return $this->extractorVersion;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getFolded(): ?string
    {
        return $this->folded;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getOcrPage(): ?int
    {
        return $this->ocrPage;
    }

    public function getIndexedAt():\DateTimeImmutable
    {
        return $this->indexedAt;
    }
}
