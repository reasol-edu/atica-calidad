<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentSection>
 */
class DocumentSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentSection::class);
    }

    /** @return DocumentSection[] */
    public function findRootsByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.educationalCentre = :centre')
            ->andWhere('ds.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('ds.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return DocumentSection[] */
    public function findChildrenByParent(DocumentSection $parent): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->orderBy('ds.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<DocumentSection> whose name matches $query anywhere in the centre's tree, ordered by name */
    public function searchByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.educationalCentre = :centre')
            ->andWhere('LOWER(ds.name) LIKE LOWER(:query)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('ds.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?DocumentSection
    {
        $result = $this->createQueryBuilder('ds')
            ->where('ds.id = :id')
            ->andWhere('ds.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DocumentSection ? $result : null;
    }

    public function nextRootPosition(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('ds')
            ->select('COUNT(ds.id)')
            ->where('ds.educationalCentre = :centre')
            ->andWhere('ds.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function nextChildPosition(DocumentSection $parent): int
    {
        return (int) $this->createQueryBuilder('ds')
            ->select('COUNT(ds.id)')
            ->where('ds.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The whole centre's tree in one query, ordered so that a stable pre-order walk can be
     * rebuilt in memory (siblings sorted by position) — needed to render the full drag-and-drop
     * editor at once. Avoids recursive SQL, which isn't portable across PostgreSQL/MySQL/SQLite.
     *
     * @return DocumentSection[]
     */
    public function findAllByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('ds')
            ->where('ds.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('ds.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
