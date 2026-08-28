<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
}
