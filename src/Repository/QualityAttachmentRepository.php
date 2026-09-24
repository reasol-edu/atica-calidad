<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use App\Entity\QualityAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<QualityAttachment>
 */
class QualityAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QualityAttachment::class);
    }

    /** Looked up by bare id; callers must check it belongs to a finding they can see. */
    public function findById(string $id): ?QualityAttachment
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('q')
            ->where('q.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof QualityAttachment ? $result : null;
    }

    public function countByFile(DocumentFile $file): int
    {
        return $this->count(['file' => $file]);
    }
}
