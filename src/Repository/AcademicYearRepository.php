<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcademicYear>
 */
class AcademicYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicYear::class);
    }

    public function findById(string $id): ?AcademicYear
    {
        $result = $this->createQueryBuilder('ay')
            ->where('ay.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AcademicYear ? $result : null;
    }

    /** @return AcademicYear[] */
    public function findByCentreOrderedByName(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('ay')
            ->where('ay.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('ay.name', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByCentreAndId(EducationalCentre $centre, string $yearId): ?AcademicYear
    {
        $result = $this->createQueryBuilder('ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('ay.id = :id')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('id', $yearId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AcademicYear ? $result : null;
    }
}
