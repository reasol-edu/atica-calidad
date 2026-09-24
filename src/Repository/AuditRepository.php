<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Audit;
use App\Entity\AuditItem;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Audit>
 */
class AuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Audit::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?Audit
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Audit ? $result : null;
    }

    public function findItemByIdAndCentre(string $id, EducationalCentre $centre): ?AuditItem
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('i')
            ->from(AuditItem::class, 'i')
            ->join('i.audit', 'a')
            ->where('i.id = :id')
            ->andWhere('a.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AuditItem ? $result : null;
    }

    /** @return list<string> the centre's audit codes starting with $prefix ("AI-2026-") */
    public function findCodesStartingWith(EducationalCentre $centre, string $prefix): array
    {
        /** @var list<array{code: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.code AS code')
            ->where('a.educationalCentre = :centre')
            ->andWhere('a.code LIKE :prefix')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'code');
    }
}
