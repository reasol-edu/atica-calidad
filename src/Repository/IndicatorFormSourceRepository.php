<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Indicator;
use App\Entity\IndicatorFormSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<IndicatorFormSource>
 */
class IndicatorFormSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicatorFormSource::class);
    }

    /** @return list<IndicatorFormSource> the indicators fed from an activity's documents */
    public function findByActivity(\App\Entity\Activity $activity): array
    {
        return $this->findBy(['activity' => $activity]);
    }

    /** @return list<IndicatorFormSource> the indicators fed from the documents of any activity that uses a model */
    public function findByModel(\App\Entity\FormModel $model): array
    {
        /** @var list<IndicatorFormSource> $found */
        $found = $this->createQueryBuilder('s')
            ->join('s.activity', 'a')
            ->join('s.indicator', 'i')->addSelect('i')
            ->where('a.formModel = :model')
            ->setParameter('model', $model->getId(), 'uuid')
            ->orderBy('i.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function findByIndicator(Indicator $indicator): ?IndicatorFormSource
    {
        return $this->findOneBy(['indicator' => $indicator]);
    }
}
