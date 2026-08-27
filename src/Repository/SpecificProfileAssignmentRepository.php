<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpecificProfileAssignment>
 */
class SpecificProfileAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpecificProfileAssignment::class);
    }

    /**
     * Teachers assigned to one assignable unit: the profile directly
     * ($listItem === null) or one specific leaf ($listItem = that leaf).
     *
     * @return Teacher[]
     */
    public function findTeachersByProfileAndListItem(SpecificProfile $profile, ?ListItem $listItem): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a', 't')
            ->join('a.teacher', 't')
            ->where('a.specificProfile = :profile')
            ->setParameter('profile', $profile->getId(), 'uuid')
            ->orderBy('t.name.lastName', 'ASC');

        if ($listItem === null) {
            $qb->andWhere('a.listItem IS NULL');
        } else {
            $qb->andWhere('a.listItem = :listItem')
                ->setParameter('listItem', $listItem->getId(), 'uuid');
        }

        /** @var SpecificProfileAssignment[] $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (SpecificProfileAssignment $a): Teacher => $a->getTeacher(), $rows);
    }

    /**
     * Number of teachers assigned per leaf, keyed by list item UUID (RFC4122).
     * Single grouped query; avoids N+1 across the profile's leaf descendants.
     *
     * @param  ListItem[] $leaves
     * @return array<string, int>
     */
    public function findTeacherCountsByListItems(SpecificProfile $profile, array $leaves): array
    {
        if ($leaves === []) {
            return [];
        }

        $qb           = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.listItem) AS lid', 'COUNT(a.id) AS cnt')
            ->where('a.specificProfile = :profile')
            ->setParameter('profile', $profile->getId(), 'uuid');
        $placeholders = [];
        foreach ($leaves as $i => $leaf) {
            $placeholders[] = ":leaf{$i}";
            $qb->setParameter("leaf{$i}", $leaf->getId(), 'uuid');
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $qb
            ->andWhere('a.listItem IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('a.listItem')
            ->getQuery()
            ->getScalarResult();

        $uuidNorm = [];
        foreach ($leaves as $leaf) {
            $rfc = $leaf->getId()->toRfc4122();
            $uuidNorm[$rfc]                        = $rfc;
            $uuidNorm[$leaf->getId()->toBinary()] = $rfc;
        }

        $map = [];
        foreach ($rows as $row) {
            $key = $uuidNorm[(string) $row['lid']] ?? (string) $row['lid'];
            $map[$key] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * Total teachers assigned per profile (direct or across all its leaves,
     * whichever mode applies), keyed by profile UUID (RFC4122). Single
     * grouped query; avoids N+1 across a list of profiles.
     *
     * @param  SpecificProfile[] $profiles
     * @return array<string, int>
     */
    public function findAssignedTeacherCountsByProfiles(array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }

        $qb           = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.specificProfile) AS pid', 'COUNT(DISTINCT a.teacher) AS cnt');
        $placeholders = [];
        foreach ($profiles as $i => $profile) {
            $placeholders[] = ":profile{$i}";
            $qb->setParameter("profile{$i}", $profile->getId(), 'uuid');
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $qb
            ->where('a.specificProfile IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('a.specificProfile')
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

    /** Whether any assignment references this list item — blocks its deletion. */
    public function isListItemAssigned(ListItem $item): bool
    {
        return $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.listItem = :item')
            ->setParameter('item', $item->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
