<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
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
    public function findRootsByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('sp')
            ->where('sp.educationalCentre = :centre')
            ->andWhere('sp.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('sp.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return SpecificProfile[] */
    public function findChildrenByParent(SpecificProfile $parent): array
    {
        return $this->createQueryBuilder('sp')
            ->where('sp.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
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

    public function nextRootPosition(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('sp')
            ->select('COUNT(sp.id)')
            ->where('sp.educationalCentre = :centre')
            ->andWhere('sp.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function nextChildPosition(SpecificProfile $parent): int
    {
        return (int) $this->createQueryBuilder('sp')
            ->select('COUNT(sp.id)')
            ->where('sp.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Number of teachers assigned per profile, keyed by profile UUID (RFC4122).
     * Single grouped query; avoids N+1 across a list of profiles.
     *
     * @param  SpecificProfile[] $profiles
     * @return array<string, int>
     */
    public function findTeacherCountsByProfiles(array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }

        $qb           = $this->createQueryBuilder('sp')
            ->select('sp.id AS pid', 'COUNT(t.id) AS cnt')
            ->leftJoin('sp.teachers', 't');
        $placeholders = [];
        foreach ($profiles as $i => $profile) {
            $placeholders[] = ":profile{$i}";
            $qb->setParameter("profile{$i}", $profile->getId(), 'uuid');
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $qb
            ->where('sp IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('sp.id')
            ->getQuery()
            ->getScalarResult();

        $uuidNorm = [];
        foreach ($profiles as $profile) {
            $rfc = $profile->getId()->toRfc4122();
            $uuidNorm[$rfc]                          = $rfc;
            $uuidNorm[$profile->getId()->toBinary()] = $rfc;
        }

        $map = [];
        foreach ($rows as $row) {
            $key = $uuidNorm[(string) $row['pid']] ?? (string) $row['pid'];
            $map[$key] = (int) $row['cnt'];
        }

        return $map;
    }
}
