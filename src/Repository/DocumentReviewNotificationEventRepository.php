<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentReviewNotificationEvent;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentReviewNotificationEvent>
 */
class DocumentReviewNotificationEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentReviewNotificationEvent::class);
    }

    /**
     * Every queued event of the centre (all three kinds mixed), oldest first — feeds
     * SendDocumentReviewDigestHandler, which buckets them per teacher/kind and deletes the whole
     * batch once every teacher's digest for the day has been built.
     *
     * @return list<DocumentReviewNotificationEvent>
     */
    public function findByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.documentRevision', 'r')
            ->join('r.document', 'd')
            ->join('d.folder', 'f')
            ->join('f.documentSection', 's')
            ->where('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
