<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentReadAcknowledgement;
use App\Entity\DocumentRevision;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentReadAcknowledgement>
 */
class DocumentReadAcknowledgementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentReadAcknowledgement::class);
    }

    public function findOneByRevisionAndTeacher(DocumentRevision $revision, Teacher $teacher): ?DocumentReadAcknowledgement
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.revision = :revision')
            ->andWhere('a.teacher = :teacher')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DocumentReadAcknowledgement ? $result : null;
    }

    /**
     * $teacher's acknowledgements among $revisions, in one query.
     *
     * @param list<DocumentRevision> $revisions
     *
     * @return array<string, DocumentReadAcknowledgement> keyed by revision id (RFC 4122)
     */
    public function findByTeacherIndexedByRevision(Teacher $teacher, array $revisions): array
    {
        if ($revisions === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->where('a.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid');
        $this->whereRevisionIn($qb, $revisions);

        /** @var list<DocumentReadAcknowledgement> $rows */
        $rows = $qb->getQuery()->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->getRevision()->getId()->toRfc4122()] = $row;
        }

        return $indexed;
    }

    /**
     * Everyone who acknowledged any of $revisions, with the teacher fetched along.
     *
     * @param list<DocumentRevision> $revisions
     *
     * @return list<DocumentReadAcknowledgement>
     */
    public function findByRevisions(array $revisions): array
    {
        if ($revisions === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.teacher', 't');
        $this->whereRevisionIn($qb, $revisions);

        return $qb->getQuery()->getResult();
    }

    /**
     * One "uuid"-typed placeholder per revision, as in SpecificProfileAssignmentRepository: a
     * single array parameter wouldn't convert each id for the platform.
     *
     * @param non-empty-list<DocumentRevision> $revisions
     */
    private function whereRevisionIn(QueryBuilder $qb, array $revisions): void
    {
        $placeholders = [];
        foreach ($revisions as $i => $revision) {
            $placeholders[] = ":revision{$i}";
            $qb->setParameter("revision{$i}", $revision->getId(), 'uuid');
        }

        $qb->andWhere('a.revision IN (' . implode(', ', $placeholders) . ')');
    }
}
