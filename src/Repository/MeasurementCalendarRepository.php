<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\MeasurementCalendar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MeasurementCalendar>
 */
class MeasurementCalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeasurementCalendar::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?MeasurementCalendar
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->andWhere('c.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof MeasurementCalendar ? $result : null;
    }

    /** @return list<MeasurementCalendar> $year's calendars, by name, with their periods */
    public function findByYear(AcademicYear $year): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('p')
            ->leftJoin('c.periods', 'p')
            ->where('c.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
