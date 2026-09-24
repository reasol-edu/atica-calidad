<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\ImprovementActionStatus;
use App\Entity\ImprovementActionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ImprovementAction>
 */
class ImprovementActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImprovementAction::class);
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?ImprovementAction
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ImprovementAction ? $result : null;
    }

    /**
     * The improvement plan of $year: the centre's actions without a finding, filtered, soonest due
     * first (no due date last), then by code.
     *
     * @param array{status?: string, type?: string, section?: string, query?: string} $filters
     *        status: a status value, "open" (not done) or "overdue" (not done and past due, as of $today)
     *
     * @return list<ImprovementAction>
     */
    public function findPlan(EducationalCentre $centre, AcademicYear $year, array $filters = [], ?\DateTimeImmutable $today = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('t', 'p', 's', 'CASE WHEN a.dueDate IS NULL THEN 1 ELSE 0 END AS HIDDEN no_due')
            ->leftJoin('a.responsibleTeacher', 't')
            ->leftJoin('a.responsibleProfile', 'p')
            ->leftJoin('a.section', 's')
            ->where('a.educationalCentre = :centre')
            ->andWhere('a.finding IS NULL')
            ->andWhere('a.academicYear = :year')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('no_due', 'ASC')
            ->addOrderBy('a.dueDate', 'ASC')
            ->addOrderBy('a.code', 'ASC');

        $status = $filters['status'] ?? '';
        if ($status === 'open' || $status === 'overdue') {
            $qb->andWhere('a.status != :done')->setParameter('done', ImprovementActionStatus::Done->value);
            if ($status === 'overdue') {
                $qb->andWhere('a.dueDate < :today')->setParameter('today', ($today ?? new \DateTimeImmutable())->setTime(0, 0), Types::DATE_IMMUTABLE);
            }
        } elseif (ImprovementActionStatus::tryFrom($status) !== null) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        $type = ImprovementActionType::tryFrom($filters['type'] ?? '');
        if ($type !== null) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type->value);
        }
        $section = $filters['section'] ?? '';
        if (Uuid::isValid($section)) {
            $qb->andWhere('a.section = :section')->setParameter('section', $section, 'uuid');
        }
        $query = trim($filters['query'] ?? '');
        if ($query !== '') {
            $qb->andWhere('LOWER(a.code) LIKE LOWER(:q) OR UNACCENT(LOWER(a.description)) LIKE UNACCENT(LOWER(:q)) OR UNACCENT(LOWER(a.goal)) LIKE UNACCENT(LOWER(:q))')
                ->setParameter('q', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The plan actions proposed from any of $measurements.
     *
     * @param list<\App\Entity\Measurement> $measurements
     *
     * @return list<ImprovementAction>
     */
    public function findByMeasurements(array $measurements): array
    {
        if ($measurements === []) {
            return [];
        }

        // One uuid parameter each: a list of entities isn't bound as uuids.
        $qb           = $this->createQueryBuilder('a');
        $placeholders = [];
        foreach ($measurements as $i => $measurement) {
            $placeholders[] = ":m{$i}";
            $qb->setParameter("m{$i}", $measurement->getId(), 'uuid');
        }

        return $qb->where('a.measurement IN (' . implode(', ', $placeholders) . ')')->getQuery()->getResult();
    }

    /**
     * The centre's action codes starting with $prefix ("PM-2026-"), for FindingCodeGenerator.
     *
     * @return list<string>
     */
    public function findCodesStartingWith(EducationalCentre $centre, string $prefix): array
    {
        /** @var list<array{code: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.code AS code')
            ->where('a.educationalCentre = :centre')
            ->andWhere('a.code LIKE :prefix')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'code');
    }

    /**
     * The centre's actions due between $from and $to (inclusive), done or not, whose finding (if
     * any) is still open or closed — not discarded. Whose they are is resolved by the caller.
     *
     * @return list<ImprovementAction>
     */
    public function findDueBetween(EducationalCentre $centre, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('f', 't', 'p')
            ->leftJoin('a.finding', 'f')
            ->leftJoin('a.responsibleTeacher', 't')
            ->leftJoin('a.responsibleProfile', 'p')
            ->where('a.educationalCentre = :centre')
            ->andWhere('a.dueDate BETWEEN :from AND :to')
            ->andWhere('f.id IS NULL OR f.status != :discarded')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('discarded', FindingStatus::Discarded->value)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The centre's actions not done yet, soonest due first (no due date last) — who they belong to
     * is resolved by the caller, as a profile's holders aren't expressible here.
     *
     * @return list<ImprovementAction>
     */
    public function findOpenByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('f', 't', 'p')
            ->leftJoin('a.finding', 'f')
            ->leftJoin('a.responsibleTeacher', 't')
            ->leftJoin('a.responsibleProfile', 'p')
            ->where('a.educationalCentre = :centre')
            ->andWhere('a.status != :done')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('done', ImprovementActionStatus::Done->value)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
