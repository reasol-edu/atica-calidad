<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\ActivityReminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityReminder>
 */
class ActivityReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityReminder::class);
    }

    /** @return list<ActivityReminder> the activity's reminders, newest first, with who they were for and from */
    public function findByActivity(Activity $activity, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('t', 's')
            ->join('r.recipient', 't')
            ->leftJoin('r.sentBy', 's')
            ->where('r.activity = :activity')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->orderBy('r.sentAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * When each teacher was last reminded about the activity (delivered or not).
     *
     * @return array<string, \DateTimeImmutable> teacher id => date
     */
    public function lastSentByTeacher(Activity $activity): array
    {
        /** @var list<array{teacherId: mixed, lastSent: string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('t.id AS teacherId', 'MAX(r.sentAt) AS lastSent')
            ->join('r.recipient', 't')
            ->where('r.activity = :activity')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->groupBy('t.id')
            ->getQuery()
            ->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $teacherId = $row['teacherId'];
            $id        = $teacherId instanceof \Symfony\Component\Uid\Uuid ? $teacherId->toRfc4122() : (string) (\is_scalar($teacherId) ? $teacherId : '');
            $last[$id] = new \DateTimeImmutable($row['lastSent']);
        }

        return $last;
    }
}
