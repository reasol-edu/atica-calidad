<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\QualityAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DocumentFile>
 */
class DocumentFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentFile::class);
    }

    public function findByHash(string $hash): ?DocumentFile
    {
        return $this->findOneBy(['hash' => $hash]);
    }

    /**
     * Deletes every file no revision nor quality attachment points to any more — left behind when
     * a whole folder goes (its documents, trashed ones included, go with it at database level,
     * without passing through DocumentFileGarbageCollector). In one statement, without loading any
     * content.
     *
     * @return int how many were deleted
     */
    public function deleteOrphans(): int
    {
        return $this->getEntityManager()
            ->createQuery(\sprintf(
                'DELETE FROM %s f WHERE NOT EXISTS (SELECT 1 FROM %s r WHERE r.file = f) AND NOT EXISTS (SELECT 1 FROM %s q WHERE q.file = f)',
                DocumentFile::class,
                DocumentRevision::class,
                QualityAttachment::class,
            ))
            ->execute();
    }

    /** Looked up by bare id; callers must verify ownership before trusting it. */
    public function findById(string $id): ?DocumentFile
    {
        $result = $this->createQueryBuilder('f')
            ->where('f.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DocumentFile ? $result : null;
    }

    /**
     * Writes a file's content straight to $path, one file at a time, without hydrating the
     * DocumentFile (which Doctrine would then keep, content included, for the rest of the
     * request). Lets a caller walk through many files — e.g. a whole folder's ZIP — holding just
     * one in memory at a time. Queried on its own on purpose: a single result set carrying every
     * content would be buffered whole by MySQL's driver.
     */
    public function writeContentTo(Uuid $id, string $path): void
    {
        $metadata = $this->getClassMetadata();
        $sql      = \sprintf('SELECT %s FROM %s WHERE %s = ?', $metadata->getColumnName('content'), $metadata->getTableName(), $metadata->getColumnName('id'));
        $content  = $this->getEntityManager()->getConnection()->fetchOne($sql, [$id], ['uuid']);

        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException(\sprintf('Cannot write to "%s".', $path));
        }

        try {
            match (true) {
                is_resource($content) => stream_copy_to_stream($content, $out),
                is_string($content)   => fwrite($out, $content),
                default               => throw new \RuntimeException(\sprintf('Document file "%s" not found.', $id->toRfc4122())),
            };
        } finally {
            fclose($out);
        }
    }
}
