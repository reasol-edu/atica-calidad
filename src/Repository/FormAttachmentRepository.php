<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use App\Entity\FormAttachment;
use App\Entity\FormSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FormAttachment>
 */
class FormAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormAttachment::class);
    }

    /** Looked up by bare id; callers must check who may see its submission. */
    public function findById(string $id): ?FormAttachment
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $found = $this->find(Uuid::fromString($id));

        return $found instanceof FormAttachment ? $found : null;
    }

    /**
     * The attachments of a submission among $ids, by id (what a value of the data may point at: only its own submission's), in ONE
     * query and without loading any content.
     *
     * @param list<string> $ids
     *
     * @return array<string, FormAttachment>
     */
    public function findOfSubmission(FormSubmission $submission, array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => Uuid::isValid($id)));
        if ($ids === []) {
            return [];
        }
        // Bound one by one: binary UUID columns do not match an array of them on SQLite/MySQL (see skills/forms.md).
        $qb = $this->createQueryBuilder('a')->where('a.submission = :submission')->setParameter('submission', $submission->getId(), 'uuid');
        $or = [];
        foreach ($ids as $i => $id) {
            $or[] = 'a.id = :id' . $i;
            $qb->setParameter('id' . $i, Uuid::fromString($id), 'uuid');
        }
        $qb->andWhere(implode(' OR ', $or));

        $out = [];
        /** @var list<FormAttachment> $found */
        $found = $qb->getQuery()->getResult();
        foreach ($found as $attachment) {
            $out[$attachment->getId()->toRfc4122()] = $attachment;
        }

        return $out;
    }

    public function countByFile(DocumentFile $file): int
    {
        return $this->count(['file' => $file]);
    }
}
