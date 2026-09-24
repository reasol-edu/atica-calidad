<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ManagementReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ManagementReview>
 */
class ManagementReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagementReview::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?ManagementReview
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->andWhere('r.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ManagementReview ? $result : null;
    }

    /** @return list<ManagementReview> the centre's reviews, the most recent first */
    public function findByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.heldOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** The review held just before $review, if any. */
    public function findPrevious(ManagementReview $review): ?ManagementReview
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.educationalCentre = :centre')
            ->andWhere('r.heldOn < :heldOn')
            ->setParameter('centre', $review->getEducationalCentre()->getId(), 'uuid')
            ->setParameter('heldOn', $review->getHeldOn(), 'date_immutable')
            ->orderBy('r.heldOn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ManagementReview ? $result : null;
    }

    /** The centre's most recent review, if any. */
    public function findLatest(EducationalCentre $centre): ?ManagementReview
    {
        return $this->findByCentre($centre)[0] ?? null;
    }
}
