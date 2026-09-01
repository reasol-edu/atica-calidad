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
     */
    public function findOneForOwner(Activity $activity, ?Teacher $teacher, ?SpecificProfile $profile, ?ListItem $listItem): ?ActivityCompletion
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.activity = :activity')
            ->setParameter('activity', $activity->getId(), 'uuid');

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
}
