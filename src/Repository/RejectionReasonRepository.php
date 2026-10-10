<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\RejectionReason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RejectionReason>
 */
class RejectionReasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RejectionReason::class);
    }

    /** @return list<RejectionReason> the centre's own reasons, in the order they were set */
    public function findByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Drops every reason of the centre (the next save, or the defaults, take over). */
    public function deleteByCentre(EducationalCentre $centre): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }
}
