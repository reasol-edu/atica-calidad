<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\FormModel;
use App\Entity\FormSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormSubmission>
 */
class FormSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmission::class);
    }

    /** @return list<FormSubmission> every cycle of a slot, the most recent first */
    public function findByActivitySlot(Activity $activity, string $slotKey): array
    {
        /** @var list<FormSubmission> $found */
        $found = $this->findBy(['activity' => $activity, 'slotKey' => $slotKey], ['cycleYear' => 'DESC']);

        return $found;
    }

    /** @return list<FormSubmission> the submissions (drafts and delivered) of every activity filled with the model */
    public function findForModel(FormModel $model): array
    {
        /** @var list<FormSubmission> $found */
        $found = $this->createQueryBuilder('s')
            ->join('s.activity', 'a')
            ->where('a.formModel = :model')
            ->setParameter('model', $model->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return $found;
    }

    /** @return list<FormSubmission> every submission of an activity in one academic-year cycle */
    public function findByActivityCycle(Activity $activity, int $cycleYear): array
    {
        /** @var list<FormSubmission> $found */
        $found = $this->findBy(['activity' => $activity, 'cycleYear' => $cycleYear]);

        return $found;
    }

    public function findBySlot(Activity $activity, string $slotKey, int $cycleYear): ?FormSubmission
    {
        return $this->findOneBy(['activity' => $activity, 'slotKey' => $slotKey, 'cycleYear' => $cycleYear]);
    }
}
