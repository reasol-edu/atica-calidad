<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Indicator;
use App\Entity\IndicatorFormSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicatorFormSource>
 */
class IndicatorFormSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicatorFormSource::class);
    }

    public function findByIndicator(Indicator $indicator): ?IndicatorFormSource
    {
        return $this->findOneBy(['indicator' => $indicator]);
    }
}
