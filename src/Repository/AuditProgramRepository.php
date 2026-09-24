<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\AuditProgram;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditProgram>
 */
class AuditProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditProgram::class);
    }

    /** $year's programme, with its audits, their teams and scope. */
    public function findByYear(AcademicYear $year): ?AuditProgram
    {
        $result = $this->createQueryBuilder('p')
            ->addSelect('a', 'l', 'au', 's')
            ->leftJoin('p.audits', 'a')
            ->leftJoin('a.leadAuditor', 'l')
            ->leftJoin('a.auditors', 'au')
            ->leftJoin('a.scope', 's')
            ->where('p.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AuditProgram ? $result : null;
    }
}
