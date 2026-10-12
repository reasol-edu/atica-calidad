<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<EducationalCentre>
 */
class EducationalCentreRepository extends ServiceEntityRepository
{
    /**
     * Which centres a plain teacher (no global admin role) may work in — one condition shared by
     * the list and the single-centre check, on alias "ec" and parameter ":tid". They're the ones
     * where they're listed as an admin, or — a plain teacher belongs to a centre by being listed as
     * a teacher of one of its academic years, any year, not just the active one — a teacher.
     */
    private const string ACCESSIBLE_BY_TEACHER = '(
        EXISTS (SELECT a.id FROM ' . EducationalCentre::class . ' ecAdmin JOIN ecAdmin.admins a WHERE ecAdmin.id = ec.id AND a.id = :tid)
        OR EXISTS (SELECT t.id FROM ' . AcademicYear::class . ' ay JOIN ay.teachers t WHERE ay.educationalCentre = ec AND t.id = :tid)
    )';

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

    /**
     * The centre the session has selected, with its active year and its admins, quality managers
     * and internal auditors in the same query: every request needs the year (the layout) and
     * whether the user holds one of those roles (the access check, the navigation and the voters),
     * which would each cost a query on their own. The role lists are a handful of people each.
     */
    public function findByIdForTenant(string $id): ?EducationalCentre
    {
        $result = $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('ec.admins', 'adm')
            ->addSelect('adm')
            ->leftJoin('ec.qualityManagers', 'qm')
            ->addSelect('qm')
            ->leftJoin('ec.internalAuditors', 'ia')
            ->addSelect('ia')
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
            ->orderBy('ec.name')
            ->getQuery()
            ->getResult();
    }

    /** @return EducationalCentre[] */
    public function findAllWithActiveYear(): array
    {
        return $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay')
            ->orderBy('ec.name')
            ->getQuery()
            ->getResult();
    }

    /** @return Query<null, EducationalCentre> */
    public function createAllWithActiveYearQuery(): Query
    {
        return $this->createAllWithActiveYearFilteredQuery();
    }

    /** Sortable columns: public key => DQL field (allowlist against injection). */
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

        $dir = strtolower($sortDir) === 'desc' ? SortDirection::Descending : SortDirection::Ascending;
        $qb->orderBy(self::SORTABLE[$sort] ?? 'ec.name', isset(self::SORTABLE[$sort]) ? $dir : SortDirection::Ascending);

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->where(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(ec.name)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(ec.code)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(ec.city)) LIKE UNACCENT(LOWER(:q))',
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

        /** @var list<EducationalCentre> $centres */
        $centres = $this->getEntityManager()
            ->createQuery('SELECT ec FROM ' . EducationalCentre::class . ' ec WHERE ' . self::ACCESSIBLE_BY_TEACHER)
            ->setParameter('tid', $teacher->getId(), 'uuid')
            ->getResult();
        usort($centres, static fn(EducationalCentre $a, EducationalCentre $b) => strcmp($a->getName(), $b->getName()));

        return $centres;
    }

    /** Whether $teacher may work in $centre — the same rule as findAccessibleByTeacher(). */
    public function isAccessibleByTeacher(EducationalCentre $centre, Teacher $teacher): bool
    {
        if ($teacher->isAdmin()) {
            return true;
        }

        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(ec.id) FROM ' . EducationalCentre::class . ' ec WHERE ec.id = :cid AND ' . self::ACCESSIBLE_BY_TEACHER)
            ->setParameter('cid', $centre->getId(), 'uuid')
            ->setParameter('tid', $teacher->getId(), 'uuid')
            ->getSingleScalarResult() > 0;
    }
}
