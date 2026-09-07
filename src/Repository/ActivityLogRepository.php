<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * @param array{
     *   dateFrom?: string,
     *   dateTo?: string,
     *   userQuery?: string,
     *   centreId?: string,
     *   actionType?: string,
     *   sort?: string,
     *   sortDir?: string,
     * } $filters
     * @return Query<null, ActivityLog>
     */
    public function createFilteredQuery(array $filters = []): Query
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('u', 'r', 'c', 'y')
            ->leftJoin('l.activeUser', 'u')
            ->leftJoin('l.realUser', 'r')
            ->leftJoin('l.educationalCentre', 'c')
            ->leftJoin('l.academicYear', 'y');

        if (!empty($filters['dateFrom'])) {
            try {
                $qb->andWhere('l.createdAt >= :dateFrom')
                    ->setParameter('dateFrom', new \DateTimeImmutable($filters['dateFrom']));
            } catch (\Exception) {
            }
        }

        if (!empty($filters['dateTo'])) {
            try {
                $qb->andWhere('l.createdAt <= :dateTo')
                    ->setParameter('dateTo', new \DateTimeImmutable($filters['dateTo']));
            } catch (\Exception) {
            }
        }

        $userQuery = trim($filters['userQuery'] ?? '');
        if ($userQuery !== '') {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(u.name.firstName) LIKE LOWER(:userQuery)',
                'LOWER(u.name.lastName) LIKE LOWER(:userQuery)',
                'LOWER(u.username) LIKE LOWER(:userQuery)',
                'LOWER(r.name.firstName) LIKE LOWER(:userQuery)',
                'LOWER(r.name.lastName) LIKE LOWER(:userQuery)',
                'LOWER(r.username) LIKE LOWER(:userQuery)',
            ))->setParameter('userQuery', '%' . $userQuery . '%');
        }

        if (!empty($filters['centreId'])) {
            $qb->andWhere('c.id = :centreId')->setParameter('centreId', $filters['centreId'], 'uuid');
        }

        if (!empty($filters['actionType'])) {
            $qb->andWhere('l.actionType = :actionType')->setParameter('actionType', $filters['actionType']);
        }

        $allowedSorts = ['createdAt' => 'l.createdAt', 'ip' => 'l.ip', 'actionType' => 'l.actionType'];
        $sort         = $allowedSorts[$filters['sort'] ?? ''] ?? 'l.createdAt';
        $sortDir      = strtoupper($filters['sortDir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $qb->orderBy($sort, $sortDir)->addOrderBy('l.id', $sortDir);

        return $qb->getQuery();
    }

    /** @return list<string> distinct action types seen in the log, alphabetically */
    public function findDistinctActionTypes(): array
    {
        /** @var list<array{actionType: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.actionType')
            ->orderBy('l.actionType', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'actionType');
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
