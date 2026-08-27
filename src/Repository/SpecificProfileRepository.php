<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpecificProfile>
 */
class SpecificProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpecificProfile::class);
    }

    /** @return SpecificProfile[] */
    public function findByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('sp')
            ->where('sp.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('sp.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?SpecificProfile
    {
        $result = $this->createQueryBuilder('sp')
            ->where('sp.id = :id')
            ->andWhere('sp.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SpecificProfile ? $result : null;
    }

    public function nextPosition(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('sp')
            ->select('COUNT(sp.id)')
            ->where('sp.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Whether any profile is associated with this list item — blocks its deletion. */
    public function isListItemInUse(ListItem $item): bool
    {
        return $this->createQueryBuilder('sp')
            ->select('1')
            ->where('sp.listItem = :item')
            ->setParameter('item', $item->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
