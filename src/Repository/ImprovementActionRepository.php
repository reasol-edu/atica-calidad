<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ImprovementAction>
 */
class ImprovementActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImprovementAction::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?ImprovementAction
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

        return $result instanceof ImprovementAction ? $result : null;
    }

    /**
     * The centre's actions not done yet, soonest due first (no due date last) — who they belong to
     * is resolved by the caller, as a profile's holders aren't expressible here.
     *
     * @return list<ImprovementAction>
     */
    public function findOpenByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('f', 't', 'p')
            ->leftJoin('a.finding', 'f')
            ->leftJoin('a.responsibleTeacher', 't')
            ->leftJoin('a.responsibleProfile', 'p')
            ->where('a.educationalCentre = :centre')
            ->andWhere('a.status != :done')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('done', ImprovementActionStatus::Done->value)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
