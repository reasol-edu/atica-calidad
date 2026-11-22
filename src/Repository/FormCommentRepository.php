<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormComment;
use App\Entity\FormSubmissionRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FormComment>
 */
class FormCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormComment::class);
    }

    /**
     * The comments of a delivered revision, oldest first, with their authors.
     *
     * @return list<FormComment>
     */
    public function findByRevision(FormSubmissionRevision $revision): array
    {
        /** @var list<FormComment> $comments */
        $comments = $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->where('c.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('c.createdAt')
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function findByIdAndRevision(string $id, FormSubmissionRevision $revision): ?FormComment
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $comment = $this->findOneBy(['id' => Uuid::fromString($id), 'revision' => $revision]);

        return $comment instanceof FormComment ? $comment : null;
    }
}
