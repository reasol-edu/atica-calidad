<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolEvent>
 */
class SchoolEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolEvent::class);
    }

    /** @return Query<null, SchoolEvent> */
    public function findNoneQuery(): Query
    {
        return $this->createQueryBuilder('se')
            ->where('1 = 0')
            ->getQuery();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?SchoolEvent
    {
        $result = $this->createQueryBuilder('se')
            ->where('se.academicYear = :year')
            ->andWhere('se.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SchoolEvent ? $result : null;
    }

    /** @return list<SchoolEvent> */
    public function findAllForAcademicYearAndDate(AcademicYear $year, \DateTimeImmutable $date): array
    {
        return $this->baseQueryBuilder($year)
            ->andWhere('se.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SchoolEvent> */
    public function findVisibleForTeacherAndDate(Teacher $viewer, AcademicYear $year, \DateTimeImmutable $date): array
    {
        return $this->visibleForTeacherQueryBuilder($viewer, $year)
            ->andWhere('se.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SchoolEvent> */
    public function findAllForAcademicYear(AcademicYear $year): array
    {
        return $this->baseQueryBuilder($year)->getQuery()->getResult();
    }

    /** @return list<SchoolEvent> */
    public function findVisibleForTeacherInAcademicYear(Teacher $viewer, AcademicYear $year): array
    {
        return $this->visibleForTeacherQueryBuilder($viewer, $year)->getQuery()->getResult();
    }

    /** @return Query<null, SchoolEvent> */
    public function createFilteredQuery(AcademicYear $year, string $search = '', string $groupId = ''): Query
    {
        $qb = $this->baseQueryBuilder($year)
            ->orderBy('se.date', 'DESC')
            ->addOrderBy('se.startTime', 'ASC');

        if ($groupId !== '') {
            $qb->join('se.groups', 'fg')
                ->andWhere('fg.id = :groupId')
                ->setParameter('groupId', $groupId, 'uuid');
        }

        if ($search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(se.name) LIKE LOWER(:search)',
                    'LOWER(se.description) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery();
    }

    private function baseQueryBuilder(AcademicYear $year): QueryBuilder
    {
        return $this->createQueryBuilder('se')
            ->distinct()
            ->where('se.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid');
    }

    private function visibleForTeacherQueryBuilder(Teacher $viewer, AcademicYear $year): QueryBuilder
    {
        $qb = $this->baseQueryBuilder($year);
        $qb->leftJoin('se.groups', 'g')
            ->leftJoin('g.groupTeachers', 'gt')
            ->andWhere($qb->expr()->orX(
                'se.general = true',
                'gt.teacher = :viewer',
                ':viewer MEMBER OF g.tutors',
            ))
            ->setParameter('viewer', $viewer->getId(), 'uuid');

        return $qb;
    }
}
