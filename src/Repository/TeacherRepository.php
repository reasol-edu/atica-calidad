<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

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
                    'LOWER(t.name.firstName) LIKE LOWER(:q)',
                    'LOWER(t.name.lastName) LIKE LOWER(:q)',
                    'LOWER(t.username) LIKE LOWER(:q)',
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
                    'LOWER(t.name.firstName) LIKE LOWER(:q)',
                    'LOWER(t.name.lastName) LIKE LOWER(:q)',
                    'LOWER(t.username) LIKE LOWER(:q)',
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
     * ¿Está ya este correo asignado o pendiente de verificar por otro docente?
     * Coincidencia insensible a mayúsculas; se excluye al propio docente cuando
     * se pasa, para que el editor de perfil pueda «refrescar» su propio correo.
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
            ->where('LOWER(t.name.firstName) LIKE LOWER(:q)')
            ->orWhere('LOWER(t.name.lastName) LIKE LOWER(:q)')
            ->orWhere('LOWER(t.username) LIKE LOWER(:q)')
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
}
