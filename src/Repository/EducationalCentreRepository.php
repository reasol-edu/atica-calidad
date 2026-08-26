<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EducationalCentre>
 */
class EducationalCentreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EducationalCentre::class);
    }

    public function findByCode(string $code): ?EducationalCentre
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findById(string $id): ?EducationalCentre
    {
        $result = $this->createQueryBuilder('ec')
            ->where('ec.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof EducationalCentre ? $result : null;
    }

    public function findByIdWithActiveYear(string $id): ?EducationalCentre
    {
        $result = $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay')
            ->where('ec.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof EducationalCentre ? $result : null;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('ec')
            ->select('COUNT(ec.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return EducationalCentre[] */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('ec')
            ->orderBy('ec.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return EducationalCentre[] */
    public function findAllWithActiveYear(): array
    {
        return $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay')
            ->orderBy('ec.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Query<null, EducationalCentre> */
    public function createAllWithActiveYearQuery(): Query
    {
        return $this->createAllWithActiveYearFilteredQuery();
    }

    /** Columnas ordenables: clave pública => campo DQL (allowlist contra inyección). */
    private const SORTABLE = [
        'code' => 'ec.code',
        'name' => 'ec.name',
        'city' => 'ec.city',
    ];

    /** @return Query<null, EducationalCentre> */
    public function createAllWithActiveYearFilteredQuery(string $search = '', string $sort = '', string $sortDir = 'asc'): Query
    {
        $qb = $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay');

        $dir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $qb->orderBy(self::SORTABLE[$sort] ?? 'ec.name', isset(self::SORTABLE[$sort]) ? $dir : 'ASC');

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->where(
                $qb->expr()->orX(
                    'LOWER(ec.name) LIKE LOWER(:q)',
                    'LOWER(ec.code) LIKE LOWER(:q)',
                    'LOWER(ec.city) LIKE LOWER(:q)',
                )
            )->setParameter('q', $q);
        }

        return $qb->getQuery();
    }

    /** @return EducationalCentre[] */
    public function findAccessibleByTeacher(Teacher $teacher): array
    {
        if ($teacher->isAdmin()) {
            return $this->findAllOrderedByName();
        }

        $merged = [];

        $tid = $teacher->getId();

        // Centres where teacher is listed as admin
        foreach ($this->createQueryBuilder('ec')
            ->join('ec.admins', 'a')
            ->where('a.id = :tid')
            ->setParameter('tid', $tid, 'uuid')
            ->getQuery()
            ->getResult() as $centre) {
            $merged[$centre->getId()->toRfc4122()] = $centre;
        }

        // Centres via group membership — navigate from Group since ManyToOne associations are unidirectional
        foreach ($this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->from(Group::class, 'g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->leftJoin('g.groupTeachers', 'ggt')
            ->leftJoin('g.tutors', 'gtu')
            ->where('ggt.teacher = :tid OR gtu.id = :tid')
            ->setParameter('tid', $tid, 'uuid')
            ->distinct()
            ->getQuery()
            ->getResult() as $group) {
            $ec = $group->getAcademicYear()->getEducationalCentre();
            $merged[$ec->getId()->toRfc4122()] = $ec;
        }

        $centres = array_values($merged);
        usort($centres, static fn(EducationalCentre $a, EducationalCentre $b) => strcmp($a->getName(), $b->getName()));

        return $centres;
    }
}
