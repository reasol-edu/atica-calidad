<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\ActivityCompletion;
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

    /** @return ActivityCompletion[] every completion recorded for this activity, for building stats/badges in one query. */
    public function findByActivity(Activity $activity): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.activity = :activity')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->getQuery()
            ->getResult();
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
