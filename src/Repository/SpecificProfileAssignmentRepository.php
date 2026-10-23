<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ProfileAssignmentRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpecificProfileAssignment>
 */
class SpecificProfileAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ListItemRepository $listItems)
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
            ->orderBy('t.name.lastName');

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
     * Teachers who currently hold this profile/subprofile combination — either a direct assignment
     * to that leaf (or to any leaf under it, when $listItem is an intermediate node of the
     * profile's list), or a whole-profile (wildcard, listItem IS NULL) assignment that covers every
     * subprofile. A null $listItem on a list-associated profile is the "whole profile" pick: every
     * teacher holding any of its subprofiles. Unlike findTeachersByProfileAndListItem() (the
     * exact-match version the per-leaf admin assignment screen needs, since there it must
     * show/manage the specific assignment row for that leaf), this answers "who can act as this
     * row" — same convention as isTeacherAssignedToAny(). Used to expand a folder upload row into one
     * submission slot per teacher for Activity::SCOPE_INDIVIDUAL. See
     * findTeachersHoldingProfileAndListItemForPairs() for the batched version, needed when a
     * caller has to resolve many (profile, listItem) pairs in one go.
     *
     * @return Teacher[]
     */
    public function findTeachersHoldingProfileAndListItem(SpecificProfile $profile, ?ListItem $listItem): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a', 't')
            ->join('a.teacher', 't')
            ->where('a.specificProfile = :profile')
            ->setParameter('profile', $profile->getId(), 'uuid')
            ->orderBy('t.name.lastName');

        if ($listItem !== null) {
            $placeholders = [];
            foreach ($this->listItems->findLeafIdsUnder($listItem) as $i => $leafId) {
                $placeholders[] = ":leaf{$i}";
                $qb->setParameter("leaf{$i}", $leafId, 'uuid');
            }
            $qb->andWhere('a.listItem IN (' . implode(', ', $placeholders) . ') OR a.listItem IS NULL');
        } elseif ($profile->getListItem() === null) {
            $qb->andWhere('a.listItem IS NULL');
        }

        /** @var SpecificProfileAssignment[] $rows */
        $rows = $qb->getQuery()->getResult();

        $teachers = [];
        $seen     = [];
        foreach ($rows as $row) {
            $teacher = $row->getTeacher();
            $key     = $teacher->getId()->toRfc4122();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $teachers[] = $teacher;
        }

        return $teachers;
    }

    /**
     * Batched findTeachersHoldingProfileAndListItem(): fetches every assignment for the involved
     * profiles in one query, then applies the same wildcard-counts-as-any matching in memory per
     * pair. A caller resolving many pairs at once (e.g. every submission slot of an activity) would
     * otherwise run the single-pair query once per pair, which is the same shape executed over and
     * over with only the bound profile/listItem differing.
     *
     * @param  array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs
     * @return array<string, Teacher[]> keyed by ProfileAssignmentRow::keyFor($profile, $listItem)
     */
    public function findTeachersHoldingProfileAndListItemForPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->select('a', 't')
            ->join('a.teacher', 't')
            ->orderBy('t.name.lastName');

        $profileIds   = [];
        $placeholders = [];
        foreach ($pairs as $pair) {
            $profileIds[$pair[0]->getId()->toRfc4122()] = $pair[0]->getId();
        }
        foreach (array_values($profileIds) as $i => $profileId) {
            $placeholders[] = ":profile{$i}";
            $qb->setParameter("profile{$i}", $profileId, 'uuid');
        }

        /** @var SpecificProfileAssignment[] $rows */
        $rows = $qb
            ->where('a.specificProfile IN (' . implode(', ', $placeholders) . ')')
            ->getQuery()
            ->getResult();

        /** @var array<string, SpecificProfileAssignment[]> $byProfile */
        $byProfile = [];
        foreach ($rows as $row) {
            $byProfile[$row->getSpecificProfile()->getId()->toRfc4122()][] = $row;
        }

        $result = [];
        foreach ($pairs as $pair) {
            [$profile, $listItem] = $pair;
            $teachers = [];
            $seen     = [];
            $coveredLeafIds = $listItem === null ? [] : $this->listItems->findLeafIdsUnder($listItem);
            foreach ($byProfile[$profile->getId()->toRfc4122()] ?? [] as $row) {
                $rowListItem = $row->getListItem();
                $matches     = match (true) {
                    $listItem !== null               => $rowListItem === null || \in_array($rowListItem->getId()->toRfc4122(), $coveredLeafIds, true),
                    $profile->getListItem() !== null => true,
                    default                          => $rowListItem === null,
                };
                if (!$matches) {
                    continue;
                }
                $teacher = $row->getTeacher();
                $tKey    = $teacher->getId()->toRfc4122();
                if (isset($seen[$tKey])) {
                    continue;
                }
                $seen[$tKey] = true;
                $teachers[]  = $teacher;
            }
            $result[ProfileAssignmentRow::keyFor($profile, $listItem)] = $teachers;
        }

        return $result;
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
     * Whether this teacher currently holds any of the given profile/subprofile pairs. A null list
     * item in a pair means either of two things depending on the profile itself: for a profile
     * with no list association, it means the profile directly (the only way it's ever assigned);
     * for a profile that DOES have a list association, it's a "whole profile" selection meaning
     * any of its subprofiles counts — used to let a restriction target every subprofile of a
     * profile at once without enumerating them. A non-null list item that isn't a leaf stands for
     * every leaf under it. Used to check folder/section permissions against a restriction list.
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
                $placeholders = [];
                foreach ($this->listItems->findLeafIdsUnder($listItem) as $j => $leafId) {
                    $placeholders[] = ":leaf{$i}_{$j}";
                    $qb->setParameter("leaf{$i}_{$j}", $leafId, 'uuid');
                }
                $conditions[] = "(a.specificProfile = :profile{$i} AND a.listItem IN (" . implode(', ', $placeholders) . '))';
            } elseif ($profile->getListItem() !== null) {
                $conditions[] = "(a.specificProfile = :profile{$i})";
            } else {
                $conditions[] = "(a.specificProfile = :profile{$i} AND a.listItem IS NULL)";
            }
        }

        $qb->andWhere(implode(' OR ', $conditions));

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    /**
     * For every profile the teacher holds, the list items a restriction would have to be on to
     * cover what they hold: each held leaf and all of its ancestors (a restriction on "FP" covers
     * whoever holds a leaf under it). A profile held with no leaf (a plain profile) maps to an empty
     * list. What lets a query ask "is this restriction one of the teacher's?" with a plain IN.
     *
     * @return array<string, list<string>> profile id => RFC4122 ids of the list items covering what they hold there
     */
    public function findCoveringItemIdsByTeacher(Teacher $teacher): array
    {
        /** @var SpecificProfileAssignment[] $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a', 'li')
            ->leftJoin('a.listItem', 'li')
            ->where('a.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $covering = [];
        foreach ($rows as $row) {
            $profileId = $row->getSpecificProfile()->getId()->toRfc4122();
            $covering[$profileId] ??= [];
            $item = $row->getListItem();
            if ($item !== null) {
                $covering[$profileId] = array_values(array_unique([...$covering[$profileId], ...$this->listItems->findSelfAndAncestorIds($item)]));
            }
        }

        return $covering;
    }

    /**
     * Every profile (and subprofile, '' for none) the teacher holds, in one query: what lets a
     * loop over many (profile, subprofile) questions about one teacher answer them in memory.
     *
     * @return array<string, list<string>> profile id => subprofile ids ('' when the assignment has none)
     */
    public function findHeldByTeacher(Teacher $teacher): array
    {
        /** @var list<array{profileId: \Symfony\Component\Uid\Uuid, listItemId: \Symfony\Component\Uid\Uuid|null}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('p.id AS profileId', 'li.id AS listItemId')
            ->join('a.specificProfile', 'p')
            ->leftJoin('a.listItem', 'li')
            ->where('a.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $held = [];
        foreach ($rows as $row) {
            $held[$row['profileId']->toRfc4122()][] = $row['listItemId']?->toRfc4122() ?? '';
        }

        return $held;
    }

    /**
     * The same for every teacher of the centre at once.
     *
     * @return array<string, array<string, list<string>>> teacher id => profile id => subprofile ids ('' for none); teachers holding nothing have no entry
     */
    public function findHeldByCentre(EducationalCentre $centre): array
    {
        /** @var list<array{teacherId: \Symfony\Component\Uid\Uuid, profileId: \Symfony\Component\Uid\Uuid, listItemId: \Symfony\Component\Uid\Uuid|null}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('t.id AS teacherId', 'p.id AS profileId', 'li.id AS listItemId')
            ->join('a.teacher', 't')
            ->join('a.specificProfile', 'p')
            ->leftJoin('a.listItem', 'li')
            ->where('p.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $held = [];
        foreach ($rows as $row) {
            $held[$row['teacherId']->toRfc4122()][$row['profileId']->toRfc4122()][] = $row['listItemId']?->toRfc4122() ?? '';
        }

        return $held;
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
