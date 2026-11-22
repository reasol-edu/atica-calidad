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
            ->orderBy('sp.position')
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

    /**
     * The ids of the list items a profile of the centre takes its list from, in ONE query (what `isListItemInUse()` asks per item).
     *
     * @return array<string, true> RFC4122 ids
     */
    public function findListItemIdsInUse(EducationalCentre $centre): array
    {
        return \App\Repository\IdSet::of($this->createQueryBuilder('sp')
            ->select('DISTINCT li.id')
            ->join('sp.listItem', 'li')
            ->where('sp.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleColumnResult());
    }
}
