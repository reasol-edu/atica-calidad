<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\FormApprovalStep;
use App\Entity\FormApprovalStepState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormApprovalStep>
 */
class FormApprovalStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormApprovalStep::class);
    }

    /**
     * The steps of a delivered revision, in order.
     *
     * @return list<FormApprovalStep>
     */
    public function findByRevision(DocumentRevision $revision): array
    {
        /** @var list<FormApprovalStep> $steps */
        $steps = $this->createQueryBuilder('s')
            ->join('s.revision', 'fr')
            ->where('fr.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('s.position')
            ->getQuery()
            ->getResult();

        return $steps;
    }

    /**
     * Every step waiting for a decision right now in the centre, with what the profile and the document are (for the queue of the
     * people who hold those profiles).
     *
     * @return list<FormApprovalStep>
     */
    public function findPendingByCentre(EducationalCentre $centre): array
    {
        /** @var list<FormApprovalStep> $steps */
        $steps = $this->createQueryBuilder('s')
            ->join('s.revision', 'fr')->addSelect('fr')
            ->join('fr.revision', 'r')->addSelect('r')
            ->join('r.document', 'd')->addSelect('d')
            ->join('d.folder', 'f')->addSelect('f')
            ->join('f.documentSection', 'sec')
            ->where('s.state = :pending')->andWhere('sec.educationalCentre = :centre')
            ->setParameter('pending', FormApprovalStepState::Pending->value)
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.revisedAt')
            ->getQuery()
            ->getResult();

        return $steps;
    }
}
