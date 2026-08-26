<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailNotificationLog>
 */
class EmailNotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailNotificationLog::class);
    }

    /**
     * @param array{
     *   search?: string,
     *   eventKey?: string,
     *   status?: string,
     *   dateFrom?: string,
     *   dateTo?: string,
     * } $filters
     * @return Query<null, EmailNotificationLog>
     */
    public function createFilteredQuery(EducationalCentre $centre, array $filters = []): Query
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('r')
            ->leftJoin('l.recipient', 'r')
            ->where('l.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('l.sentAt', 'DESC');

        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(l.recipientName) LIKE LOWER(:search)',
                    'LOWER(l.subject) LIKE LOWER(:search)',
                    'LOWER(r.name.firstName) LIKE LOWER(:search)',
                    'LOWER(r.name.lastName) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        if (!empty($filters['eventKey'])) {
            $qb->andWhere('l.eventKey = :eventKey')->setParameter('eventKey', $filters['eventKey']);
        }

        if (($filters['status'] ?? '') === 'success') {
            $qb->andWhere('l.success = true');
        } elseif (($filters['status'] ?? '') === 'failed') {
            $qb->andWhere('l.success = false');
        }

        if (!empty($filters['dateFrom'])) {
            try {
                $from = new \DateTimeImmutable($filters['dateFrom']);
                $qb->andWhere('l.sentAt >= :dateFrom')->setParameter('dateFrom', $from);
            } catch (\Exception) {
            }
        }

        if (!empty($filters['dateTo'])) {
            try {
                $to = new \DateTimeImmutable($filters['dateTo']);
                $qb->andWhere('l.sentAt <= :dateTo')->setParameter('dateTo', $to);
            } catch (\Exception) {
            }
        }

        return $qb->getQuery();
    }

    /** @return list<string> */
    public function findDistinctEventKeys(EducationalCentre $centre): array
    {
        /** @var list<array{eventKey: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.eventKey')
            ->where('l.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('l.eventKey', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'eventKey');
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.sentAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
