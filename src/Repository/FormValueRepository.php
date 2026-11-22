<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\FormValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Parameter;
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
     * How many delivered documents have a choice answered with any of these list items (a multiple choice keeps its items joined in
     * one value, so a value that merely contains the id counts). Counted by chunks of ids: a document that has items of two chunks
     * is counted twice, which only matters for a branch of hundreds of items.
     *
     * @param list<string> $itemIds RFC4122 ids of list items
     */
    public function countDocumentsUsingItems(array $itemIds): int
    {
        $total = 0;
        foreach (array_chunk($itemIds, 40) as $chunk) {
            $conditions = [];
            $parameters = [];
            foreach ($chunk as $i => $id) {
                $conditions[]          = 'v.text = :e' . $i . ' OR v.text LIKE :l' . $i;
                $parameters['e' . $i]  = $id;
                $parameters['l' . $i]  = '%' . $id . '%';
            }
            $total += (int) $this->createQueryBuilder('v')
                ->select('COUNT(DISTINCT v.submissionRevision)')
                ->where(implode(' OR ', $conditions))
                ->setParameters(new ArrayCollection(array_map(static fn (string $name, string $value): Parameter => new Parameter($name, $value), array_keys($parameters), $parameters)))
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $total;
    }

    /**
     * Every value of every delivered revision of an activity's cycle (the caller keeps the revisions it wants), in the order of the
     * revision, copy and row they belong to.
     *
     * @return list<FormValue>
     */
    public function findByActivityCycle(Activity $activity, int $cycleYear): array
    {
        /** @var list<FormValue> $found */
        $found = $this->createQueryBuilder('v')
            ->join('v.submissionRevision', 'sr')->addSelect('sr')
            ->join('sr.submission', 's')
            ->where('s.activity = :activity')
            ->andWhere('s.cycleYear = :cycle')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->setParameter('cycle', $cycleYear)
            ->orderBy('v.path')->addOrderBy('v.rowKey')
            ->getQuery()
            ->getResult();

        return $found;
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
