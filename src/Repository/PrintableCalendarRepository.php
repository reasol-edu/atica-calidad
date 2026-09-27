<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PrintableCalendar;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PrintableCalendar>
 */
class PrintableCalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrintableCalendar::class);
    }

    /** @return list<PrintableCalendar> $owner's calendars for $year, most recently created first */
    public function findByOwnerAndYear(Teacher $owner, AcademicYear $year): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.owner = :owner')
            ->andWhere('c.academicYear = :year')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Only ever $owner's own, in $centre — never resolves another teacher's calendar, nor one of
     * the teacher's own calendars left over from a different centre, whatever id is passed.
     */
    public function findByOwnerAndId(Teacher $owner, EducationalCentre $centre, string $id): ?PrintableCalendar
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('c')
            ->addSelect('p', 'd')
            ->leftJoin('c.periods', 'p')
            ->leftJoin('c.dates', 'd')
            ->where('c.id = :id')
            ->andWhere('c.owner = :owner')
            ->andWhere('c.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof PrintableCalendar ? $result : null;
    }
}
