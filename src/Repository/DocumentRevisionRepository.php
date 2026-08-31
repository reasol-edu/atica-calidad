<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentRevision>
 */
class DocumentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentRevision::class);
    }

    /**
     * Most recent first.
     *
     * @return DocumentRevision[]
     */
    public function findByDocument(Document $document): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.document = :document')
            ->setParameter('document', $document->getId(), 'uuid')
            ->orderBy('r.version', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndDocument(string $id, Document $document): ?DocumentRevision
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->andWhere('r.document = :document')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('document', $document->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DocumentRevision ? $result : null;
    }

    /** Used by DocumentFileGarbageCollector to decide whether a blob is still referenced. */
    public function countByFile(DocumentFile $file): int
    {
        return $this->count(['file' => $file]);
    }

    /**
     * Every revision awaiting review in the centre, oldest first — reviewer eligibility per folder
     * is a service-level check (DocumentTreeAccessChecker::canReviewFolder()), not expressible here,
     * so callers filter this list themselves. Feeds the notification bell's "pending review" items.
     *
     * @return list<DocumentRevision>
     */
    public function findPendingReviewByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.document', 'd')
            ->join('d.folder', 'f')
            ->join('f.documentSection', 's')
            ->where('r.pendingReview = true')
            ->andWhere('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.revisedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
