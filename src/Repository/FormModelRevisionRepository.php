<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentRevision;
use App\Entity\FormModel;
use App\Entity\FormModelRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FormModelRevision>
 */
class FormModelRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormModelRevision::class);
    }

    public function findByRevision(DocumentRevision $revision): ?FormModelRevision
    {
        return $this->findOneBy(['revision' => $revision]);
    }

    /** The last revision sent of a model (pending review or not): what the draft is compared with to know what is still to send. */
    public function findLatest(FormModel $model): ?FormModelRevision
    {
        /** @var FormModelRevision|null $found */
        $found = $this->createQueryBuilder('r')
            ->join('r.revision', 'dr')
            ->where('r.model = :model')
            ->setParameter('model', $model->getId(), 'uuid')
            ->orderBy('dr.version', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $found;
    }

    /** The revision in force of a model: the one its document has approved and active. Null while none was approved. */
    public function findInForce(FormModel $model): ?FormModelRevision
    {
        $active = $model->getDocument()->getActiveRevision();

        return $active === null ? null : $this->findByRevision($active);
    }

    /**
     * The revision in force of every model of a centre, in ONE query (what `findInForce()` costs per model, several queries each, for a
     * screen that walks all the activities).
     *
     * @return array<string, FormModelRevision> by the id of the model
     */
    public function findAllInForceByCentre(\App\Entity\EducationalCentre $centre): array
    {
        /** @var list<FormModelRevision> $found */
        $found = $this->createQueryBuilder('r')
            ->join('r.model', 'm')->addSelect('m')
            ->join('m.document', 'd')
            ->join('d.folder', 'f')
            ->join('f.documentSection', 's')
            ->where('s.educationalCentre = :centre')
            ->andWhere('r.revision = d.activeRevision')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $byModel = [];
        foreach ($found as $revision) {
            $byModel[$revision->getModel()->getId()->toRfc4122()] = $revision;
        }

        return $byModel;
    }

    public function findByIdAndModel(string $id, FormModel $model): ?FormModelRevision
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'model' => $model]);
    }
}
