<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Finding>
 */
class FindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Finding::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?Finding
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('f')
            ->where('f.id = :id')
            ->andWhere('f.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Finding ? $result : null;
    }

    /**
     * The centre's findings in $status, oldest reported first.
     *
     * @return list<Finding>
     */
    public function findByCentreAndStatus(EducationalCentre $centre, FindingStatus $status): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.educationalCentre = :centre')
            ->andWhere('f.status = :status')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('status', $status->value)
            ->orderBy('f.reportedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What $teacher has reported in the centre, most recent first ("Mis incidencias").
     *
     * @return list<Finding>
     */
    public function findReportedBy(Teacher $teacher, EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.educationalCentre = :centre')
            ->andWhere('f.reportedBy = :teacher')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->orderBy('f.reportedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nonconformities waiting for $teacher's cause analysis, soonest due first.
     *
     * @return list<Finding>
     */
    public function findAwaitingAnalysisBy(Teacher $teacher, EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.educationalCentre = :centre')
            ->andWhere('f.status = :status')
            ->andWhere('f.analysisResponsible = :teacher')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('status', FindingStatus::Analysis->value)
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->orderBy('f.analysisDueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> number of the centre's findings by status value */
    public function countByStatus(EducationalCentre $centre): array
    {
        /** @var list<array{status: \App\Entity\FindingStatus|string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.status AS status', 'COUNT(f.id) AS n')
            ->where('f.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->groupBy('f.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status          = $row['status'] instanceof FindingStatus ? $row['status']->value : $row['status'];
            $counts[$status] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The findings an internal audit's report raised, in the order of its checklist.
     *
     * @return list<Finding>
     */
    public function findByAudit(\App\Entity\Audit $audit): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.auditItem', 'i')
            ->where('i.audit = :audit')
            ->setParameter('audit', $audit->getId(), 'uuid')
            ->orderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The findings opened from any of $measurements.
     *
     * @param list<\App\Entity\Measurement> $measurements
     *
     * @return list<Finding>
     */
    public function findByMeasurements(array $measurements): array
    {
        if ($measurements === []) {
            return [];
        }

        // One uuid parameter each: a list of entities isn't bound as uuids.
        $qb           = $this->createQueryBuilder('f');
        $placeholders = [];
        foreach ($measurements as $i => $measurement) {
            $placeholders[] = ":m{$i}";
            $qb->setParameter("m{$i}", $measurement->getId(), 'uuid');
        }

        return $qb->where('f.measurement IN (' . implode(', ', $placeholders) . ')')->getQuery()->getResult();
    }

    /**
     * The codes already given with $prefix in $year ("NC-2026-…"), to number the next one.
     *
     * @return list<string>
     */
    public function findCodesStartingWith(EducationalCentre $centre, string $prefix): array
    {
        /** @var list<array{code: string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.code AS code')
            ->where('f.educationalCentre = :centre')
            ->andWhere('f.code LIKE :prefix')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'code');
    }

    /**
     * The findings list, filtered. Every filter is optional; values are the enums' string values,
     * a section id, a teacher id, or a calendar year ("2026").
     *
     * @param array{status?: string, kind?: string, origin?: string, section?: string, responsible?: string, year?: string, query?: string} $filters
     *
     * @return Query<null, Finding>
     */
    public function createFilteredQuery(EducationalCentre $centre, array $filters = []): Query
    {
        $qb = $this->createQueryBuilder('f')
            ->addSelect('s', 'r', 'a')
            ->leftJoin('f.section', 's')
            ->leftJoin('f.reportedBy', 'r')
            ->leftJoin('f.analysisResponsible', 'a')
            ->where('f.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid');

        if (($filters['status'] ?? '') === 'open') {
            $qb->andWhere('f.status NOT IN (:closed)')->setParameter('closed', [FindingStatus::Closed->value, FindingStatus::Discarded->value]);
        } elseif (($filters['status'] ?? '') !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['kind'] ?? '') !== '') {
            $qb->andWhere('f.kind = :kind')->setParameter('kind', $filters['kind']);
        }
        if (($filters['origin'] ?? '') !== '') {
            $qb->andWhere('f.origin = :origin')->setParameter('origin', $filters['origin']);
        }
        if (($filters['section'] ?? '') !== '' && Uuid::isValid($filters['section'])) {
            $qb->andWhere('s.id = :section')->setParameter('section', $filters['section'], 'uuid');
        }
        if (($filters['responsible'] ?? '') !== '' && Uuid::isValid($filters['responsible'])) {
            $qb->andWhere('a.id = :responsible')->setParameter('responsible', $filters['responsible'], 'uuid');
        }
        if (($filters['year'] ?? '') !== '' && ctype_digit($filters['year'])) {
            $year = (int) $filters['year'];
            $qb->andWhere('f.reportedAt >= :from AND f.reportedAt < :to')
                ->setParameter('from', new \DateTimeImmutable("{$year}-01-01"))
                ->setParameter('to', new \DateTimeImmutable(($year + 1) . '-01-01'));
        }
        $query = trim($filters['query'] ?? '');
        if ($query !== '') {
            $qb->andWhere('UNACCENT(LOWER(f.title)) LIKE UNACCENT(LOWER(:q)) OR LOWER(f.code) LIKE LOWER(:q) OR UNACCENT(LOWER(f.description)) LIKE UNACCENT(LOWER(:q))')
                ->setParameter('q', '%' . $query . '%');
        }

        return $qb->orderBy('f.reportedAt', 'DESC')->getQuery();
    }

    /** @return list<int> the calendar years with findings, most recent first */
    public function findYears(EducationalCentre $centre): array
    {
        /** @var list<array{reportedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.reportedAt AS reportedAt')
            ->where('f.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $years = [];
        foreach ($rows as $row) {
            $years[(int) $row['reportedAt']->format('Y')] = true;
        }
        krsort($years);

        return array_keys($years);
    }
}
