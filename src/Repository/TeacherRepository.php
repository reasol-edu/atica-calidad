<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\AuditStatus;
use App\Entity\FindingStatus;
use App\Entity\ImprovementActionStatus;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Teacher>
 */
class TeacherRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Teacher::class);
    }

    public function findById(string $id): ?Teacher
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Teacher ? $result : null;
    }

    /** @return Teacher[] */
    public function findByAcademicYearOrderedByName(AcademicYear $year): array
    {
        return $this->createByAcademicYearFilteredQuery($year)->getResult();
    }

    public function countByAcademicYear(AcademicYear $year): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.academicYears', 'ay')
            ->where('ay.id = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?Teacher
    {
        $result = $this->createQueryBuilder('t')
            ->join('t.academicYears', 'ay')
            ->where('t.id = :id')
            ->andWhere('ay.id = :year')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Teacher ? $result : null;
    }

/** @return Teacher[] */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Query<null, Teacher> */
    public function createOrderedByNameQuery(): Query
    {
        return $this->createFilteredOrderedByNameQuery();
    }

    /** @return Query<null, Teacher> */
    public function createFilteredOrderedByNameQuery(string $search = ''): Query
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC');

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->where(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(t.name.firstName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.name.lastName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.username)) LIKE UNACCENT(LOWER(:q))',
                )
            )->setParameter('q', $q);
        }

        return $qb->getQuery();
    }

    /** @return Query<null, Teacher> */
    public function createByAcademicYearFilteredQuery(AcademicYear $year, string $search = ''): Query
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.academicYears', 'ay')
            ->where('ay.id = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC');

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(t.name.firstName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.name.lastName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.username)) LIKE UNACCENT(LOWER(:q))',
                )
            )->setParameter('q', $q);
        }

        return $qb->getQuery();
    }

    /** @return Query<null, Teacher> */
    public function findNoneQuery(): Query
    {
        return $this->createQueryBuilder('t')
            ->where('1 = 0')
            ->getQuery();
    }

    public function findByUsername(string $username): ?Teacher
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findByEmailVerificationToken(string $token): ?Teacher
    {
        return $this->findOneBy(['emailVerificationToken' => Teacher::hashToken($token)]);
    }

    /**
     * Is this email already assigned to, or pending verification by, another teacher?
     * Case-insensitive match; the teacher themself is excluded when passed,
     * so the profile editor can "refresh" their own email.
     */
    public function isEmailTakenByAnother(string $email, ?Teacher $exclude = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->where('LOWER(t.email) = LOWER(:email) OR LOWER(t.pendingEmail) = LOWER(:email)')
            ->setParameter('email', $email)
            ->setMaxResults(1);

        if ($exclude !== null) {
            $qb->andWhere('t.id != :selfId')->setParameter('selfId', $exclude->getId(), 'uuid');
        }

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    public function findByPasswordResetToken(string $token): ?Teacher
    {
        return $this->findOneBy(['passwordResetToken' => Teacher::hashToken($token)]);
    }

    public function findByFullName(string $firstName, string $lastName): ?Teacher
    {
        $result = $this->createQueryBuilder('t')
            ->where('LOWER(t.name.firstName) = LOWER(:firstName)')
            ->andWhere('LOWER(t.name.lastName) = LOWER(:lastName)')
            ->setParameter('firstName', $firstName)
            ->setParameter('lastName', $lastName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Teacher ? $result : null;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Teacher) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** @return Teacher[] */
    public function search(string $query, int $limit = 10): array
    {
        $q = '%' . $query . '%';

        return $this->createQueryBuilder('t')
            ->where('UNACCENT(LOWER(t.name.firstName)) LIKE UNACCENT(LOWER(:q))')
            ->orWhere('UNACCENT(LOWER(t.name.lastName)) LIKE UNACCENT(LOWER(:q))')
            ->orWhere('UNACCENT(LOWER(t.username)) LIKE UNACCENT(LOWER(:q))')
            ->setParameter('q', $q)
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAdmins(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.admin = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Quick search by name / username for the global search palette.
     *
     * @return list<Teacher>
     */
    public function searchByAcademicYear(AcademicYear $year, string $q, int $limit = 5): array
    {
        /** @var list<Teacher> $result */
        $result = $this->createByAcademicYearFilteredQuery($year, $q)
            ->setMaxResults($limit)
            ->getResult();

        return $result;
    }

    /**
     * Docentes con alguna responsabilidad vigente en el centro del curso: administran el centro, son
     * responsables de calidad o auditores internos, tienen asignado algún perfil (o subperfil),
     * son responsables de una acción de mejora sin terminar de este curso, de un indicador activo
     * o del análisis de una ficha abierta, o forman parte del equipo de una auditoría sin cerrar.
     * Las asignaciones de perfil no dependen del curso: se cuentan siempre, por si acaso.
     * Quedan fuera la autoría histórica (quién subió, creó, revisó o completó algo): si no, casi
     * nadie podría retirarse del curso.
     *
     * @return array<string, true> ids (RFC 4122) de los docentes vinculados
     */
    public function findConnectedIdsForYear(AcademicYear $year): array
    {
        $centre = $year->getEducationalCentre();
        $ids    = [];

        foreach ([$centre->getAdmins(), $centre->getQualityManagers(), $centre->getInternalAuditors()] as $holders) {
            foreach ($holders as $teacher) {
                $ids[$teacher->getId()->toRfc4122()] = true;
            }
        }

        $queries = [
            ['SELECT t FROM App\Entity\Teacher t WHERE EXISTS(SELECT 1 FROM App\Entity\SpecificProfileAssignment a JOIN a.specificProfile p WHERE a.teacher = t AND p.educationalCentre = :centre)',
                ['centre' => $centre->getId()]],
            ['SELECT t FROM App\Entity\Teacher t WHERE EXISTS(SELECT 1 FROM App\Entity\ImprovementAction ia WHERE ia.responsibleTeacher = t AND ia.educationalCentre = :centre AND (ia.academicYear = :year OR ia.academicYear IS NULL) AND ia.status != :done)',
                ['centre' => $centre->getId(), 'year' => $year->getId(), 'done' => ImprovementActionStatus::Done]],
            ['SELECT t FROM App\Entity\Teacher t WHERE EXISTS(SELECT 1 FROM App\Entity\Indicator i WHERE i.responsibleTeacher = t AND i.educationalCentre = :centre AND i.active = true)',
                ['centre' => $centre->getId()]],
            ['SELECT t FROM App\Entity\Teacher t WHERE EXISTS(SELECT 1 FROM App\Entity\Finding f WHERE f.analysisResponsible = t AND f.educationalCentre = :centre AND f.status NOT IN (:closed))',
                ['centre' => $centre->getId(), 'closed' => [FindingStatus::Closed, FindingStatus::Discarded]]],
            ['SELECT t FROM App\Entity\Teacher t WHERE EXISTS(SELECT 1 FROM App\Entity\Audit au WHERE au.educationalCentre = :centre AND au.status != :closedAudit AND (au.leadAuditor = t OR t MEMBER OF au.auditors))',
                ['centre' => $centre->getId(), 'closedAudit' => AuditStatus::Closed]],
        ];

        foreach ($queries as [$dql, $parameters]) {
            $query = $this->getEntityManager()->createQuery($dql);
            foreach ($parameters as $name => $value) {
                $query->setParameter($name, $value, $value instanceof Uuid ? 'uuid' : null);
            }
            /** @var list<Teacher> $linked */
            $linked = $query->getResult();
            foreach ($linked as $teacher) {
                $ids[$teacher->getId()->toRfc4122()] = true;
            }
        }

        return $ids;
    }
}
