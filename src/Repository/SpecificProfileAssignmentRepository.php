<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
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

    /**
     * All assignments for a centre, fetch-joined with teacher, list item and profile — one query
     * to build both tabs of the cross-cutting assignments screen without N+1.
     *
     * @return SpecificProfileAssignment[]
     */
    public function findAllForCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('a')
            ->select('a', 't', 'li', 'p')
            ->join('a.teacher', 't')
            ->leftJoin('a.listItem', 'li')
            ->join('a.specificProfile', 'p')
            ->where('p.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether this teacher currently holds any of the given profile/subperfil pairs. A null list
     * item in a pair means either of two things depending on the profile itself: for a profile
     * with no list association, it means the profile directly (the only way it's ever assigned);
     * for a profile that DOES have a list association, it's a "whole profile" selection meaning
     * any of its subperfiles counts — used to let a restriction target every subperfil of a
     * profile at once without enumerating them. Used to check folder/section permissions against
     * a restriction list.
     *
     * @param array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs
     */
    public function isTeacherAssignedToAny(Teacher $teacher, array $pairs): bool
    {
        if ($pairs === []) {
            return false;
        }

        $qb = $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setMaxResults(1);

        $conditions = [];
        foreach ($pairs as $i => $pair) {
            [$profile, $listItem] = $pair;
            $qb->setParameter("profile{$i}", $profile->getId(), 'uuid');
            if ($listItem !== null) {
                $qb->setParameter("listItem{$i}", $listItem->getId(), 'uuid');
                $conditions[] = "(a.specificProfile = :profile{$i} AND a.listItem = :listItem{$i})";
            } elseif ($profile->getListItem() !== null) {
                $conditions[] = "(a.specificProfile = :profile{$i})";
            } else {
                $conditions[] = "(a.specificProfile = :profile{$i} AND a.listItem IS NULL)";
            }
        }

        $qb->andWhere(implode(' OR ', $conditions));

        return $qb->getQuery()->getOneOrNullResult() !== null;
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
