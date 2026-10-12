<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\ActivityCompletion;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityCompletion>
 */
class ActivityCompletionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityCompletion::class);
    }

    /**
     * Whether $activity has already been marked completed for this exact owner — either a teacher
     * (Individual scope) or a profile/subprofile (ByProfile scope), matched by identity including
     * NULL (a plain, non-list profile has $listItem === null, which must match exactly, not "any").
     *
     * $leaf narrows a ByProfile owner down to one specific leaf of a list-backed activity, when its
     * upload row covers more than one (see ActivityCompletionChecker::getMyCompletionOwners()). A
     * completion with a NULL leaf matches ANY $leaf, never just a strict NULL === NULL: every row
     * created before this column existed has a NULL leaf and is meant to keep covering its whole
     * owner, so splitting an existing owner into independent leaves never undoes an already
     * recorded completion.
     */
    public function findOneForOwner(Activity $activity, ?Teacher $teacher, ?SpecificProfile $profile, ?ListItem $listItem, ?ListItem $leaf, int $cycleYear): ?ActivityCompletion
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.activity = :activity')
            ->andWhere('c.cycleYear = :cycleYear')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->setParameter('cycleYear', $cycleYear);

        if ($teacher !== null) {
            $qb->andWhere('c.teacher = :teacher')->setParameter('teacher', $teacher->getId(), 'uuid');
        } else {
            $qb->andWhere('c.teacher IS NULL');
        }

        if ($profile !== null) {
            $qb->andWhere('c.profile = :profile')->setParameter('profile', $profile->getId(), 'uuid');
        } else {
            $qb->andWhere('c.profile IS NULL');
        }

        if ($listItem !== null) {
            $qb->andWhere('c.listItem = :listItem')->setParameter('listItem', $listItem->getId(), 'uuid');
        } else {
            $qb->andWhere('c.listItem IS NULL');
        }

        if ($leaf !== null) {
            $qb->andWhere('c.leafListItem IS NULL OR c.leafListItem = :leaf')->setParameter('leaf', $leaf->getId(), 'uuid');
        } else {
            $qb->andWhere('c.leafListItem IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result instanceof ActivityCompletion ? $result : null;
    }

    /**
     * Every completion of the given occurrences — each an activity with the cycle year of the
     * occurrence — whatever the owner: in one query instead of one per owner.
     *
     * @param list<array{Activity, int}> $occurrences
     *
     * @return list<ActivityCompletion>
     */
    public function findByOccurrences(array $occurrences): array
    {
        if ($occurrences === []) {
            return [];
        }

        // The activities × the cycle years asked for: a superset when they have different cycle
        // years (a handful at most), which the caller sorts out by occurrence.
        $qb           = $this->createQueryBuilder('c');
        $placeholders = [];
        foreach ($occurrences as $i => [$activity]) {
            $placeholders[] = ':activity' . $i;
            $qb->setParameter('activity' . $i, $activity->getId(), 'uuid');
        }

        /** @var list<ActivityCompletion> $result */
        $result = $qb
            ->where('c.activity IN (' . implode(', ', $placeholders) . ')')
            ->andWhere('c.cycleYear IN (:cycleYears)')
            ->setParameter('cycleYears', array_values(array_unique(array_map(static fn (array $o): int => $o[1], $occurrences))))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * How many owners have completed each of the given occurrences, in one query: counts keyed
     * "activityId|cycleYear" (an occurrence nobody completed has no entry).
     *
     * @param list<array{Activity, int}> $occurrences
     *
     * @return array<string, int>
     */
    public function countByOccurrences(array $occurrences): array
    {
        if ($occurrences === []) {
            return [];
        }

        $qb           = $this->createQueryBuilder('c')->join('c.activity', 'a');
        $placeholders = [];
        foreach ($occurrences as $i => [$activity]) {
            $placeholders[] = ':activity' . $i;
            $qb->setParameter('activity' . $i, $activity->getId(), 'uuid');
        }

        /** @var list<array{activityId: \Symfony\Component\Uid\Uuid, cycleYear: int|string, n: int|string}> $rows */
        $rows = $qb
            ->select('a.id AS activityId', 'c.cycleYear AS cycleYear', 'COUNT(c.id) AS n')
            ->where('a.id IN (' . implode(', ', $placeholders) . ')')
            ->andWhere('c.cycleYear IN (:cycleYears)')
            ->setParameter('cycleYears', array_values(array_unique(array_map(static fn (array $o): int => $o[1], $occurrences))))
            ->groupBy('a.id', 'c.cycleYear')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['activityId']->toRfc4122() . '|' . (int) $row['cycleYear']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return ActivityCompletion[] every completion recorded for this activity, for building stats/badges in one query. */
    public function findByActivity(Activity $activity): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.activity = :activity')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every completion of the centre's activities in the given cycles, as light rows — when each was
     * made, for the compliance report to tell on time from late without loading entities.
     *
     * @param list<int> $cycleYears
     *
     * @return list<array{activityId: string, cycleYear: int, completedAt: \DateTimeImmutable}>
     */
    public function findDatesByCentreAndCycles(EducationalCentre $centre, array $cycleYears): array
    {
        /** @var list<array{activityId: \Symfony\Component\Uid\Uuid, cycleYear: int, completedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('a.id AS activityId', 'c.cycleYear AS cycleYear', 'c.completedAt AS completedAt')
            ->join('c.activity', 'a')
            ->join('a.category', 'cat')
            ->where('cat.educationalCentre = :centre')
            ->andWhere('c.cycleYear IN (:cycles)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('cycles', $cycleYears)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => ['activityId' => $r['activityId']->toRfc4122(), 'cycleYear' => (int) $r['cycleYear'], 'completedAt' => $r['completedAt']], $rows);
    }

    /** @return list<int> every cycle year the centre's activities have a completion in */
    public function findCycleYearsByCentre(EducationalCentre $centre): array
    {
        /** @var list<int|string> $years */
        $years = $this->createQueryBuilder('c')
            ->select('DISTINCT c.cycleYear AS y')
            ->join('c.activity', 'a')
            ->join('a.category', 'cat')
            ->where('cat.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $y): int => (int) $y, $years);
    }

    /** How many owners have completed occurrence $cycleYear of $activity. */
    public function countByActivityAndCycle(Activity $activity, int $cycleYear): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.activity = :activity')
            ->andWhere('c.cycleYear = :cycleYear')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->setParameter('cycleYear', $cycleYear)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
