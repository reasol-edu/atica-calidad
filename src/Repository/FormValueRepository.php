<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\FormValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormValue>
 */
class FormValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormValue::class);
    }

    /**
     * The values of some fields in every delivered revision of an activity's cycle, without loading the documents (the caller keeps
     * the revisions it wants).
     *
     * @param list<string> $fieldKeys
     *
     * @return list<FormValue>
     */
    public function findByActivityCycleAndFields(Activity $activity, int $cycleYear, array $fieldKeys): array
    {
        if ($fieldKeys === []) {
            return [];
        }

        /** @var list<FormValue> $found */
        $found = $this->createQueryBuilder('v')
            ->join('v.submissionRevision', 'sr')->addSelect('sr')
            ->join('sr.submission', 's')
            ->where('s.activity = :activity')
            ->andWhere('s.cycleYear = :cycle')
            ->andWhere('v.fieldKey IN (:keys)')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->setParameter('cycle', $cycleYear)
            ->setParameter('keys', $fieldKeys)
            ->getQuery()
            ->getResult();

        return $found;
    }
}
