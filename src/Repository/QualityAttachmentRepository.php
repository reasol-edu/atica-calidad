<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentFile;
use App\Entity\DocumentFileText;
use App\Entity\EducationalCentre;
use App\Entity\QualityAttachment;
use App\Entity\TextIndexStatus;
use App\Search\TextNormalizer;
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

    /**
     * Attachments of the centre's findings, actions and audit items whose file's text contains every one of $terms (folded: see
     * TextNormalizer), newest first. Paged because the caller filters what each person may see afterwards. The text is matched in
     * SQL and never selected; the file (a BLOB) is only joined, never hydrated.
     *
     * @param list<string> $terms
     *
     * @return list<QualityAttachment>
     */
    public function searchContentByCentre(EducationalCentre $centre, array $terms, int $limit, int $offset = 0): array
    {
        if ($terms === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('q')
            ->addSelect('fi', 'ac', 'it', 'au')
            ->leftJoin('q.finding', 'fi')
            ->leftJoin('q.action', 'ac')
            ->leftJoin('q.auditItem', 'it')
            ->leftJoin('it.audit', 'au')
            ->join('q.file', 'file')
            ->join(DocumentFileText::class, 't', 'WITH', 't.file = file')
            ->where('fi.educationalCentre = :centre OR ac.educationalCentre = :centre OR au.educationalCentre = :centre')
            ->andWhere('t.status = :indexed')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('indexed', TextIndexStatus::Indexed);

        foreach ($terms as $i => $term) {
            $qb->andWhere(\sprintf("t.folded LIKE :term%d ESCAPE '!'", $i))->setParameter('term' . $i, TextNormalizer::likeContains($term));
        }

        return $qb->orderBy('q.uploadedAt', 'DESC')->addOrderBy('q.id')->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }
}
