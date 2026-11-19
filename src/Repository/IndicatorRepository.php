<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\Indicator;
use App\Search\TextNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Indicator>
 */
class IndicatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Indicator::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?Indicator
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->andWhere('i.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Indicator ? $result : null;
    }

    /**
     * The centre's indicators, by name, with their targets (and calendars and periods): all of
     * them, or only the active ones.
     *
     * @return list<Indicator>
     */
    public function findByCentre(EducationalCentre $centre, bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('t', 'c', 'p', 's', 'rt', 'rp')
            ->leftJoin('i.targets', 't')
            ->leftJoin('t.calendar', 'c')
            ->leftJoin('c.periods', 'p')
            ->leftJoin('i.section', 's')
            ->leftJoin('i.responsibleTeacher', 'rt')
            ->leftJoin('i.responsibleProfile', 'rp')
            ->where('i.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('i.name')
            ->addOrderBy('p.position');
        if ($activeOnly) {
            $qb->andWhere('i.active = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Indicators of the centre whose name or description contains $query, by name. Not filtered by who may see
     * them: the caller checks each one.
     *
     * @return list<Indicator>
     */
    public function searchByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.educationalCentre = :centre')
            ->andWhere(
                "UNACCENT(LOWER(i.name)) LIKE UNACCENT(LOWER(:query)) ESCAPE '!'"
                . " OR UNACCENT(LOWER(i.description)) LIKE UNACCENT(LOWER(:query)) ESCAPE '!'"
            )
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', TextNormalizer::likeContains($query))
            ->orderBy('i.name')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
