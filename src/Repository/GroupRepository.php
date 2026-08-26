<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function isTeacherInCourse(Teacher $teacher, Course $course): bool
    {
        return $this->createQueryBuilder('g')
            ->select('1')
            ->join('g.course', 'c')
            ->leftJoin('g.groupTeachers', 'gt')
            ->where('c = :course')
            ->andWhere(':teacher MEMBER OF g.tutors OR gt.teacher = :teacher')
            ->setParameter('course', $course->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /** @return Group[] */
    public function findByCourseOrderedByName(Course $course): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.course', 'c')
            ->where('c = :course')
            ->setParameter('course', $course->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function findByCourseAndId(Course $course, string $id): ?Group
    {
        $result = $this->createQueryBuilder('g')
            ->join('g.course', 'c')
            ->where('c = :course')
            ->andWhere('g.id = :id')
            ->setParameter('course', $course->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->distinct()
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Group ? $result : null;
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?Group
    {
        $result = $this->createQueryBuilder('g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('g.id = :id')
            ->andWhere('ay.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->distinct()
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Group ? $result : null;
    }

    public function countByActiveYearOfCentre(EducationalCentre $centre, ?AcademicYear $year = null): int
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(DISTINCT g.id)')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Group[] */
    public function findByActiveYearOfCentreOrderedByName(EducationalCentre $centre, ?AcademicYear $year = null): array
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the groups of the centre's active year that the viewer tutors, ordered by name.
     *
     * @return Group[]
     */
    public function findTutoredByActiveYear(EducationalCentre $centre, Teacher $viewer, ?AcademicYear $year = null): array
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :year')
            ->andWhere(':viewer MEMBER OF g.tutors')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns whether the viewer tutors at least one group in the given academic year.
     * Used to decide whether to show the "Mi tutoría" sidebar section.
     */
    public function hasTutoredGroupsInYear(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): bool
    {
        $qb = $this->createQueryBuilder('g');
        $qb->select('1')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :year')
            ->andWhere(':viewer MEMBER OF g.tutors')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    /**
     * Returns whether the viewer teaches or tutors any group in the given academic year.
     * Used to decide whether to show the weekly-sanctions widget on the dashboard.
     */
    public function hasTeachingGroupsInYear(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): bool
    {
        $qb = $this->createQueryBuilder('g');
        $qb->select('1')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->leftJoin('g.groupTeachers', 'gt')
            ->where('ay = :year')
            ->andWhere($qb->expr()->orX(
                'gt.teacher = :viewer',
                ':viewer MEMBER OF g.tutors',
            ))
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    /**
     * Returns teacher counts for every group in the given academic year,
     * keyed by group UUID (RFC4122). Single query; avoids N+1 per group.
     *
     * @param  Group[] $groups  All groups in the year (used to normalise binary UUIDs from getScalarResult)
     * @return array<string, array{teachers: int}>
     */
    public function findCountsByAcademicYear(AcademicYear $year, array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $this->getEntityManager()
            ->createQuery('
                SELECT g.id AS gid,
                       COUNT(DISTINCT IDENTITY(gt.teacher)) AS teachers
                FROM App\Entity\Group g
                JOIN g.course c
                JOIN c.academicYear ay
                LEFT JOIN g.groupTeachers gt
                WHERE ay = :year
                GROUP BY g.id
            ')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getScalarResult();

        // getScalarResult() returns UUIDs in binary form on MySQL.
        $uuidNorm = [];
        foreach ($groups as $group) {
            $rfc = $group->getId()->toRfc4122();
            $uuidNorm[$rfc]                        = $rfc;
            $uuidNorm[$group->getId()->toBinary()]  = $rfc;
        }
        $normalize = static fn (int|string $raw): string =>
            $uuidNorm[(string) $raw] ?? (string) $raw;

        $map = [];
        foreach ($rows as $row) {
            $map[$normalize($row['gid'])] = [
                'teachers' => (int) $row['teachers'],
            ];
        }

        return $map;
    }

    /**
     * Returns groups for the centre's active year with courses eagerly loaded,
     * sorted by course name → group name.
     *
     * @return Group[]
     */
    public function findByActiveYearOfCentreWithCourse(EducationalCentre $centre, ?AcademicYear $year = null): array
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->join('g.course', 'c')->addSelect('c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }
}
