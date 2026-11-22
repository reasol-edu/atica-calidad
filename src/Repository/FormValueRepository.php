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
    /** Ids per query: far below the parameter limit of any engine. */
    private const int ID_CHUNK = 400;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormValue::class);
    }

    /**
     * How many filled documents (a document is counted once however many times it was delivered) have a choice answered with any of
     * these list items. A multiple choice is indexed with one row per option, so this is an exact match. Counted by chunks of ids: a
     * document that has items of two chunks is counted twice, which only matters for a branch of hundreds of items.
     *
     * @param list<string> $itemIds RFC4122 ids of list items
     */
    public function countDocumentsUsingItems(array $itemIds): int
    {
        $total = 0;
        foreach (array_chunk($itemIds, self::ID_CHUNK) as $chunk) {
            // Documents, not revisions: one delivered three times counts once.
            $total += (int) $this->createQueryBuilder('v')
                ->select('COUNT(DISTINCT s.id)')
                ->join('v.submissionRevision', 'sr')
                ->join('sr.submission', 's')
                ->where('v.text IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $total;
    }

    /**
     * Which of these list items are chosen in some delivered document: one query for the lot (what an import that may remove hundreds of
     * items asks, instead of asking once per item).
     *
     * @param list<string> $itemIds RFC4122 ids of list items
     *
     * @return array<string, true> the ids that are in use
     */
    public function findItemIdsInUse(array $itemIds): array
    {
        $used = [];
        foreach (array_chunk($itemIds, self::ID_CHUNK) as $chunk) {
            $found = $this->createQueryBuilder('v')
                ->select('DISTINCT v.text')
                ->where('v.text IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->getSingleColumnResult();
            foreach ($found as $id) {
                if (is_string($id)) {
                    $used[$id] = true;
                }
            }
        }

        return $used;
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
