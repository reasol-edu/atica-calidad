<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /** @return Activity[] */
    public function findByCategory(ActivityCategory $category): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->orderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Activity> whose title matches $query anywhere in the centre's categories, ordered by title */
    public function searchByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.category', 'c')
            ->where('c.educationalCentre = :centre')
            ->andWhere('LOWER(a.title) LIKE LOWER(:query)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('a.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Looked up by bare id (the category/centre isn't known ahead of time from the URL); callers must verify ownership. */
    public function findById(string $id): ?Activity
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Activity ? $result : null;
    }

    public function findByIdAndCategory(string $id, ActivityCategory $category): ?Activity
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.category = :category')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Activity ? $result : null;
    }

    public function findByFolder(Folder $folder): ?Activity
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.folder = :folder')
            ->setParameter('folder', $folder->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Activity ? $result : null;
    }

    public function nextPosition(ActivityCategory $category): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
