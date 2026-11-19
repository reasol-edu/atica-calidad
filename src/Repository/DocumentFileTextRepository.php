<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use App\Entity\DocumentFileText;
use App\Entity\TextIndexStatus;
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
    public function save(Uuid $fileId, TextIndexStatus $status, int $version, ?string $text, ?string $folded, ?string $error, \DateTimeImmutable $at): void
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
