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

    /**
     * The latest APPROVED delivered revision of every submission of an activity's cycle, in ONE query (with the document revision, the
     * submission and the model revision it was filled with already loaded): what the indicators and the data explorer read, however
     * many slots the activity has.
     *
     * @return list<FormSubmissionRevision>
     */
    public function findLatestApprovedByActivityCycle(\App\Entity\Activity $activity, int $cycleYear): array
    {
        /** @var list<FormSubmissionRevision> $all */
        $all = $this->createQueryBuilder('s')
            ->join('s.revision', 'r')->addSelect('r')
            ->join('s.submission', 'sub')->addSelect('sub')
            ->join('s.modelRevision', 'm')->addSelect('m')
            ->where('sub.activity = :activity')
            ->andWhere('sub.cycleYear = :cycle')
            ->andWhere('r.pendingReview = false')
            ->andWhere('r.rejected = false')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->setParameter('cycle', $cycleYear)
            ->orderBy('r.version', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($all as $filled) {
            // Newest first: the first one seen of a submission is its latest.
            $latest[$filled->getSubmission()->getId()->toRfc4122()] ??= $filled;
        }

        return array_values($latest);
    }

    /**
     * The latest delivered revision of every slot of an activity in a cycle, approved or not (what a table of deliveries reads), in
     * ONE query, keyed by the slot key. The DocumentRevision comes with it (its review state); its file is never loaded.
     *
     * @return array<string, FormSubmissionRevision>
     */
    public function findLatestDeliveredByActivityCycle(\App\Entity\Activity $activity, int $cycleYear): array
    {
        /** @var list<FormSubmissionRevision> $all */
        $all = $this->createQueryBuilder('s')
            ->join('s.revision', 'r')->addSelect('r')
            ->join('s.submission', 'sub')->addSelect('sub')
            ->join('s.modelRevision', 'm')->addSelect('m')
            ->where('sub.activity = :activity')
            ->andWhere('sub.cycleYear = :cycle')
            // Only the newest of each submission: the older versions (and their data) are never loaded.
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . FormSubmissionRevision::class . ' s2 JOIN s2.revision r2 WHERE s2.submission = s.submission AND r2.version > r.version)')
            ->setParameter('activity', $activity->getId(), 'uuid')
            ->setParameter('cycle', $cycleYear)
            ->orderBy('r.version', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($all as $filled) {
            // Newest first: the first one seen of a slot is its latest.
            $latest[$filled->getSubmission()->getSlotKey()] ??= $filled;
        }

        return $latest;
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
