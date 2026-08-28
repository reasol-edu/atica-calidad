<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentSection;
use App\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Folder>
 */
class FolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    /** @return Folder[] */
    public function findBySection(DocumentSection $section): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.documentSection = :section')
            ->setParameter('section', $section->getId(), 'uuid')
            ->orderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Looked up by bare id (the section/centre isn't known ahead of time from the URL); callers must verify ownership. */
    public function findById(string $id): ?Folder
    {
        $result = $this->createQueryBuilder('f')
            ->where('f.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Folder ? $result : null;
    }

    public function findByIdAndSection(string $id, DocumentSection $section): ?Folder
    {
        $result = $this->createQueryBuilder('f')
            ->where('f.id = :id')
            ->andWhere('f.documentSection = :section')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('section', $section->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Folder ? $result : null;
    }

    public function nextPosition(DocumentSection $section): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.documentSection = :section')
            ->setParameter('section', $section->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
