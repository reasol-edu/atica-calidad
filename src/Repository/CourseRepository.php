<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\Group;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Course>
 */
class CourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Course::class);
    }

    /** @return Course[] */
    public function findByAcademicYearOrdered(AcademicYear $year): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?Course
    {
        $result = $this->createQueryBuilder('c')
            ->where('c.academicYear = :year')
            ->andWhere('c.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Course ? $result : null;
    }

    /**
     * Number of groups per course, keyed by course UUID (RFC4122). Single grouped query.
     *
     * @param  Course[] $courses
     * @return array<string, int>
     */
    public function countByCourse(array $courses): array
    {
        if ($courses === []) {
            return [];
        }

        // Join from Group side: JOIN g.course c WHERE c IN (:course0, :course1, ...)
        // Each UUID bound individually to preserve the 'uuid' type hint (same pattern as countByLevel).
        $qb           = $this->getEntityManager()->createQueryBuilder()
            ->select('c.id AS cid', 'COUNT(DISTINCT g.id) AS cnt')
            ->from(Group::class, 'g')
            ->join('g.course', 'c');
        $placeholders = [];
        foreach ($courses as $i => $course) {
            $placeholders[] = ":course{$i}";
            $qb->setParameter("course{$i}", $course->getId(), 'uuid');
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $qb
            ->where('c IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('c.id')
            ->getQuery()
            ->getScalarResult();

        $uuidNorm = [];
        foreach ($courses as $course) {
            $rfc = $course->getId()->toRfc4122();
            $uuidNorm[$rfc]                        = $rfc;
            $uuidNorm[$course->getId()->toBinary()] = $rfc;
        }

        $map = [];
        foreach ($rows as $row) {
            $key = $uuidNorm[(string) $row['cid']] ?? (string) $row['cid'];
            $map[$key] = (int) $row['cnt'];
        }

        return $map;
    }
}
