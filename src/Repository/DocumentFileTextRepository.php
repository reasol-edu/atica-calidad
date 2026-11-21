<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use App\Entity\DocumentFileText;
use App\Entity\TextIndexStatus;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DocumentFileText>
 */
class DocumentFileTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentFileText::class);
    }

    /**
     * Writes (inserts or replaces) the text of a file with plain SQL, not through the entity manager: the caller can be in the
     * middle of someone else's unit of work (a request that has just stored the file) and must not flush it. Two workers
     * reading the same file at once end up with the same row either way.
     */
    public function save(Uuid $fileId, TextIndexStatus $status, int $version, ?string $text, ?string $folded, ?string $error, \DateTimeImmutable $at, ?int $ocrPage = null): void
    {
        $metadata   = $this->getClassMetadata();
        $connection = $this->getEntityManager()->getConnection();
        $table      = $metadata->getTableName();

        $values = [
            $metadata->getColumnName('status')           => $status->value,
            $metadata->getColumnName('extractorVersion') => $version,
            $metadata->getColumnName('text')             => $text,
            $metadata->getColumnName('folded')           => $folded,
            $metadata->getColumnName('error')            => $error,
            $metadata->getColumnName('ocrPage')          => $ocrPage,
            $metadata->getColumnName('indexedAt')        => $at,
        ];
        $types = [
            $metadata->getColumnName('indexedAt') => 'datetime_immutable',
        ];
        $fileColumn = 'file_id';

        $update = fn (): int => (int) $connection->update($table, $values, [$fileColumn => $fileId], $types + [$fileColumn => 'uuid']);
        if ($update() > 0) {
            return;
        }

        try {
            $connection->insert($table, [$metadata->getColumnName('id') => Uuid::v7(), $fileColumn => $fileId] + $values, $types + [$metadata->getColumnName('id') => 'uuid', $fileColumn => 'uuid']);
        } catch (UniqueConstraintViolationException) {
            $update();
        }
    }

    /**
     * Where the OCR of a file stands: its status, the last page done (null = OCR never tried) and the text so far.
     *
     * @return array{status: TextIndexStatus, page: int|null, text: string|null}|null null when the file has no row
     */
    public function findOcrState(Uuid $fileId): ?array
    {
        $row = $this->createQueryBuilder('t')
            ->select('t.status AS status, t.ocrPage AS page, t.text AS text')
            ->where('t.file = :file')
            ->setParameter('file', $fileId, 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);
        if (!\is_array($row) || !($row['status'] ?? null) instanceof TextIndexStatus) {
            return null;
        }

        return [
            'status' => $row['status'],
            'page'   => \is_int($row['page'] ?? null) ? $row['page'] : null,
            'text'   => \is_string($row['text'] ?? null) ? $row['text'] : null,
        ];
    }

    /**
     * Records the progress of the OCR of a file — only if nobody else moved it since $expectedPage was read (two workers on the
     * same scan would otherwise append the same pages twice). Plain SQL, like save().
     *
     * @return bool whether the row was still at $expectedPage and has been updated
     */
    public function saveOcrProgress(Uuid $fileId, ?int $expectedPage, TextIndexStatus $status, int $version, ?string $text, ?string $folded, ?string $error, int $page, \DateTimeImmutable $at): bool
    {
        $metadata   = $this->getClassMetadata();
        $connection = $this->getEntityManager()->getConnection();

        $affected = $connection->update(
            $metadata->getTableName(),
            [
                $metadata->getColumnName('status')           => $status->value,
                $metadata->getColumnName('extractorVersion') => $version,
                $metadata->getColumnName('text')             => $text,
                $metadata->getColumnName('folded')           => $folded,
                $metadata->getColumnName('error')            => $error,
                $metadata->getColumnName('ocrPage')          => $page,
                $metadata->getColumnName('indexedAt')        => $at,
            ],
            ['file_id' => $fileId, $metadata->getColumnName('ocrPage') => $expectedPage],
            [$metadata->getColumnName('indexedAt') => 'datetime_immutable', 'file_id' => 'uuid', $metadata->getColumnName('ocrPage') => 'integer'],
        );

        return (int) $affected > 0;
    }

    /**
     * The files OCR still has to read, the ones already under way first and then the oldest waiting: scans recorded without text,
     * PDFs too big for the normal reader and pictures. Only types OCR can read and files up to $maxBytes. A file OCR already
     * tried (it has a page number) is not offered again.
     *
     * @param list<string> $mimeTypes
     *
     * @return list<Uuid>
     */
    public function findIdsForOcr(array $mimeTypes, int $maxBytes, int $limit): array
    {
        $ids = [];

        foreach ([[TextIndexStatus::OcrPending], [TextIndexStatus::NoText, TextIndexStatus::Unsupported, TextIndexStatus::TooLarge]] as $statuses) {
            $qb = $this->createQueryBuilder('t')
                ->select('f.id AS id')
                ->join('t.file', 'f')
                ->where('t.status IN (:statuses)')
                ->andWhere('f.mimeType IN (:types)')
                ->andWhere('f.size <= :max')
                ->setParameter('statuses', $statuses)
                ->setParameter('types', $mimeTypes)
                ->setParameter('max', $maxBytes)
                ->orderBy('t.indexedAt', 'ASC')
                ->addOrderBy('t.id', 'ASC')
                ->setMaxResults($limit - \count($ids));
            if ($statuses[0] !== TextIndexStatus::OcrPending) {
                $qb->andWhere('t.ocrPage IS NULL');
            }

            foreach ($qb->getQuery()->getArrayResult() as $row) {
                $id = \is_array($row) ? ($row['id'] ?? null) : null;
                if ($id instanceof Uuid) {
                    $ids[] = $id;
                }
            }
            if (\count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * How many files wait for OCR (not counting the ones under way).
     *
     * @param list<string> $mimeTypes
     */
    public function countAwaitingOcr(array $mimeTypes, int $maxBytes): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.file', 'f')
            ->where('t.status IN (:statuses)')
            ->andWhere('t.ocrPage IS NULL')
            ->andWhere('f.mimeType IN (:types)')
            ->andWhere('f.size <= :max')
            ->setParameter('statuses', [TextIndexStatus::NoText, TextIndexStatus::Unsupported, TextIndexStatus::TooLarge])
            ->setParameter('types', $mimeTypes)
            ->setParameter('max', $maxBytes)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Forgets what OCR read (every file it tried) so the sweep reads those files again from scratch; returns how many.
     */
    public function deleteOcrResults(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.ocrPage IS NOT NULL')
            ->getQuery()
            ->execute();
    }

    /**
     * Ids of the files not read yet, or read by an older version of the extractor, oldest first. A file that was tried with the
     * current version and failed is not offered again: it would fail the same way on every sweep.
     *
     * @return list<Uuid>
     */
    public function findIdsToIndex(int $version, int $limit): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(\sprintf(
                'SELECT f.id FROM %s f WHERE NOT EXISTS (SELECT 1 FROM %s t WHERE t.file = f AND t.extractorVersion >= :version) ORDER BY f.createdAt ASC, f.id ASC',
                DocumentFile::class,
                DocumentFileText::class,
            ))
            ->setParameter('version', $version)
            ->setMaxResults($limit)
            ->getArrayResult(); // not getScalarResult(): that one hands back the raw bytes of the id column, this one a Uuid

        $ids = [];
        foreach ($rows as $row) {
            $id = \is_array($row) ? ($row['id'] ?? null) : null;
            if ($id instanceof Uuid) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** True when the file already has a row written by $version of the extractor or a newer one. */
    public function isCurrent(Uuid $fileId, int $version): bool
    {
        return $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.file = :file')
            ->andWhere('t.extractorVersion >= :version')
            ->setParameter('file', $fileId, 'uuid')
            ->setParameter('version', $version)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * How many files are in each state, plus how many have not been read yet under $version (key "pending").
     *
     * @return array<string, int>
     */
    public function countByStatus(int $version): array
    {
        $counts = array_fill_keys(array_map(static fn (TextIndexStatus $s): string => $s->value, TextIndexStatus::cases()), 0);

        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS n')
            ->where('t.extractorVersion >= :version')
            ->setParameter('version', $version)
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            if (\is_array($row) && ($row['status'] ?? null) instanceof TextIndexStatus && is_numeric($row['n'] ?? null)) {
                $counts[$row['status']->value] = (int) $row['n'];
            }
        }

        $total            = (int) $this->getEntityManager()->createQuery(\sprintf('SELECT COUNT(f.id) FROM %s f', DocumentFile::class))->getSingleScalarResult();
        $counts['pending'] = max(0, $total - array_sum($counts));

        return $counts;
    }

    /**
     * The readable text of each file in $fileIds, by file id (RFC 4122). For the few results that survive the permission check;
     * never for a candidate list.
     *
     * @param list<Uuid> $fileIds
     *
     * @return array<string, string>
     */
    public function findTexts(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        // f.id (a joined field) comes back as a Uuid; IDENTITY(t.file) would be the raw bytes of the column.
        $qb = $this->createQueryBuilder('t')->select('f.id AS fileId, t.text AS text')->join('t.file', 'f');
        $or = [];
        foreach ($fileIds as $i => $id) {
            $or[] = 'f.id = :f' . $i;
            $qb->setParameter('f' . $i, $id, 'uuid');
        }
        $rows = $qb->where(implode(' OR ', $or))->getQuery()->getArrayResult();

        $texts = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fileId = $row['fileId'] ?? null;
            $key    = $fileId instanceof Uuid ? $fileId->toRfc4122() : (\is_string($fileId) && Uuid::isValid($fileId) ? Uuid::fromString($fileId)->toRfc4122() : null);
            if ($key !== null && \is_string($row['text'] ?? null)) {
                $texts[$key] = $row['text'];
            }
        }

        return $texts;
    }

    /** Forgets the files in $status so the sweep reads them again; returns how many. */
    public function deleteByStatus(TextIndexStatus $status): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->execute();
    }

    /** Forgets every file's text so the sweep reads them all again; returns how many. */
    public function deleteAll(): int
    {
        return (int) $this->createQueryBuilder('t')->delete()->getQuery()->execute();
    }
}
