<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\GroupTeacher;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupTeacher>
 */
class GroupTeacherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupTeacher::class);
    }

    public function findById(string $id): ?GroupTeacher
    {
        $result = $this->createQueryBuilder('gt')
            ->where('gt.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof GroupTeacher ? $result : null;
    }

    /** @return GroupTeacher[] */
    public function findByTeacherAndAcademicYearOrdered(Teacher $teacher, AcademicYear $year): array
    {
        return $this->createQueryBuilder('gt')
            ->join('gt.group', 'g')
            ->addSelect('g')
            ->join('g.course', 'c')
            ->where('gt.teacher = :teacher')
            ->andWhere('c.academicYear = :year')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->addOrderBy('gt.subject', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
