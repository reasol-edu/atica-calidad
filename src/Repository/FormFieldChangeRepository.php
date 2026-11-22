<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormFieldChange;
use App\Entity\FormSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormFieldChange>
 */
class FormFieldChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormFieldChange::class);
    }

    /**
     * The changes of one value, newest first, with who made them.
     *
     * @return list<FormFieldChange>
     */
    public function findForValue(FormSubmission $submission, string $blockKey, string $path, ?string $rowKey, ?string $columnKey, int $limit = 30): array
    {
        /** @var list<FormFieldChange> $changes */
        $changes = $this->forValue($submission, $blockKey, $path, $rowKey, $columnKey)
            ->leftJoin('c.changedBy', 'author')->addSelect('author')
            ->orderBy('c.changedAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $changes;
    }

    /** The latest change of one value, to be amended when the same person is still typing in it. */
    public function findLastForValue(FormSubmission $submission, string $blockKey, string $path, ?string $rowKey, ?string $columnKey): ?FormFieldChange
    {
        $change = $this->forValue($submission, $blockKey, $path, $rowKey, $columnKey)
            ->orderBy('c.changedAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $change instanceof FormFieldChange ? $change : null;
    }

    private function forValue(FormSubmission $submission, string $blockKey, string $path, ?string $rowKey, ?string $columnKey): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.submission = :submission')->andWhere('c.blockKey = :block')->andWhere('c.path = :path')
            ->setParameter('submission', $submission->getId(), 'uuid')
            ->setParameter('block', $blockKey)
            ->setParameter('path', $path);
        $rowKey === null ? $qb->andWhere('c.rowKey IS NULL') : $qb->andWhere('c.rowKey = :row')->setParameter('row', $rowKey);
        $columnKey === null ? $qb->andWhere('c.columnKey IS NULL') : $qb->andWhere('c.columnKey = :col')->setParameter('col', $columnKey);

        return $qb;
    }
}
