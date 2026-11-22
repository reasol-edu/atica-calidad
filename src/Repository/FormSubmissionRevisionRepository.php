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

    /** @return list<string> the ids of every delivered revision (to walk them one at a time) */
    public function findAllIds(): array
    {
        /** @var list<string> $ids */
        $ids = array_map(static fn (mixed $id): string => $id instanceof Uuid ? $id->toRfc4122() : '', $this->createQueryBuilder('s')->select('s.id')->getQuery()->getSingleColumnResult());

        return $ids;
    }

    public function findOneById(string $id): ?FormSubmissionRevision
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $result = $this->createQueryBuilder('s')->where('s.id = :id')->setParameter('id', Uuid::fromString($id), 'uuid')->getQuery()->getOneOrNullResult();

        return $result instanceof FormSubmissionRevision ? $result : null;
    }

    public function findByRevision(DocumentRevision $revision): ?FormSubmissionRevision
    {
        return $this->findOneBy(['revision' => $revision]);
    }

    /**
     * Every delivered revision of a submission, newest first.
     *
     * @return list<FormSubmissionRevision>
     */
    public function findBySubmission(FormSubmission $submission): array
    {
        /** @var list<FormSubmissionRevision> $all */
        $all = $this->createQueryBuilder('s')
            ->join('s.revision', 'r')->addSelect('r')
            ->where('s.submission = :submission')
            ->setParameter('submission', $submission->getId(), 'uuid')
            ->orderBy('r.version', 'DESC')
            ->getQuery()
            ->getResult();

        return $all;
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
