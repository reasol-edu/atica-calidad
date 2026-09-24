<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Entity\Measurement;
use App\Entity\MeasurementPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Measurement>
 */
class MeasurementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Measurement::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?Measurement
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('m')
            ->join('m.indicator', 'i')
            ->where('m.id = :id')
            ->andWhere('i.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Measurement ? $result : null;
    }

    public function findOneByIndicatorAndPeriod(Indicator $indicator, MeasurementPeriod $period): ?Measurement
    {
        return $this->findOneBy(['indicator' => $indicator, 'period' => $period]);
    }

    /**
     * Every value recorded for the centre's indicators, keyed by indicator id and then period id —
     * one query for a whole board.
     *
     * @return array<string, array<string, Measurement>>
     */
    public function findByCentreIndexed(EducationalCentre $centre): array
    {
        /** @var list<Measurement> $measurements */
        $measurements = $this->createQueryBuilder('m')
            ->addSelect('p', 'c')
            ->join('m.indicator', 'i')
            ->join('m.period', 'p')
            ->join('p.calendar', 'c')
            ->where('i.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($measurements as $m) {
            $indexed[$m->getIndicator()->getId()->toRfc4122()][$m->getPeriod()->getId()->toRfc4122()] = $m;
        }

        return $indexed;
    }
}
