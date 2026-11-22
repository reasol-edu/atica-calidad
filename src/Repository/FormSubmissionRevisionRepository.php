<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentRevision;
use App\Entity\FormSubmission;
use App\Entity\FormSubmissionRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FormSubmissionRevision>
 */
class FormSubmissionRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmissionRevision::class);
    }

    public function findByRevision(DocumentRevision $revision): ?FormSubmissionRevision
    {
        return $this->findOneBy(['revision' => $revision]);
    }

    /** The data of a delivered revision by the id of its DocumentRevision, or null (also when the id is not a UUID). */
    public function findByRevisionId(string $revisionId): ?FormSubmissionRevision
    {
        if (!Uuid::isValid($revisionId)) {
            return null;
        }

        $result = $this->createQueryBuilder('s')
            ->join('s.revision', 'r')->addSelect('r')
            ->where('r.id = :id')
            ->setParameter('id', Uuid::fromString($revisionId), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof FormSubmissionRevision ? $result : null;
    }

    /** The data of the latest delivered revision (by version), or null while nothing was delivered. */
    public function findLatest(FormSubmission $submission): ?FormSubmissionRevision
    {
        $result = $this->createQueryBuilder('s')
            ->join('s.revision', 'r')
            ->where('s.submission = :submission')
            ->setParameter('submission', $submission->getId(), 'uuid')
            ->orderBy('r.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof FormSubmissionRevision ? $result : null;
    }
}
