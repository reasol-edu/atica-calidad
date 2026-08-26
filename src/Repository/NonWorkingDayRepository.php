<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\NonWorkingDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NonWorkingDay>
 */
class NonWorkingDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NonWorkingDay::class);
    }

    /** @return NonWorkingDay[] */
    public function findByAcademicYearOrdered(AcademicYear $year): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?NonWorkingDay
    {
        $result = $this->createQueryBuilder('d')
            ->where('d.academicYear = :year')
            ->andWhere('d.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof NonWorkingDay ? $result : null;
    }

    public function findByAcademicYearAndDate(AcademicYear $year, \DateTimeImmutable $date): ?NonWorkingDay
    {
        $result = $this->createQueryBuilder('d')
            ->where('d.academicYear = :year')
            ->andWhere('d.date = :date')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof NonWorkingDay ? $result : null;
    }
}
