<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentRevision;
use App\Entity\FormModel;
use App\Entity\FormModelRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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

    /** The revision in force of a model: the one its document has approved and active. Null while none was approved. */
    public function findInForce(FormModel $model): ?FormModelRevision
    {
        $active = $model->getDocument()->getActiveRevision();

        return $active === null ? null : $this->findByRevision($active);
    }

    public function findByIdAndModel(string $id, FormModel $model): ?FormModelRevision
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'model' => $model]);
    }
}
