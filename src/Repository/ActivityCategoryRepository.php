<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityCategory>
 */
class ActivityCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityCategory::class);
    }

    /** @return ActivityCategory[] */
    public function findRootsByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.educationalCentre = :centre')
            ->andWhere('ac.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('ac.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return ActivityCategory[] */
    public function findChildrenByParent(ActivityCategory $parent): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->orderBy('ac.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<ActivityCategory> whose name matches $query anywhere in the centre's tree, ordered by name */
    public function searchByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.educationalCentre = :centre')
            ->andWhere('LOWER(ac.name) LIKE LOWER(:query)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('ac.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?ActivityCategory
    {
        $result = $this->createQueryBuilder('ac')
            ->where('ac.id = :id')
            ->andWhere('ac.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ActivityCategory ? $result : null;
    }

    public function nextRootPosition(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('ac')
            ->select('COUNT(ac.id)')
            ->where('ac.educationalCentre = :centre')
            ->andWhere('ac.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function nextChildPosition(ActivityCategory $parent): int
    {
        return (int) $this->createQueryBuilder('ac')
            ->select('COUNT(ac.id)')
            ->where('ac.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The whole centre's tree in one query, ordered so that a stable pre-order walk can be
     * rebuilt in memory (siblings sorted by position) — needed to render the full drag-and-drop
     * editor at once. Avoids recursive SQL, which isn't portable across PostgreSQL/MySQL/SQLite.
     *
     * @return ActivityCategory[]
     */
    public function findAllByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('ac.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
