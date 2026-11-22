<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\FormModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FormModel>
 */
class FormModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormModel::class);
    }

    /** A model of the centre, or null (also for another centre's; the trash filter hides a trashed one). */
    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?FormModel
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('m')
            ->join('m.document', 'd')->addSelect('d')
            ->join('d.folder', 'f')->addSelect('f')
            ->join('f.documentSection', 's')
            ->where('m.id = :id')->andWhere('s.educationalCentre = :centre')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof FormModel ? $result : null;
    }

    public function findByDocument(Document $document): ?FormModel
    {
        return $this->findOneBy(['document' => $document]);
    }

    /**
     * The centre's models, by name, with their document and folder already loaded.
     *
     * @return list<FormModel>
     */
    public function findByCentre(EducationalCentre $centre): array
    {
        /** @var list<FormModel> $models */
        $models = $this->createQueryBuilder('m')
            ->join('m.document', 'd')->addSelect('d')
            ->join('d.folder', 'f')->addSelect('f')
            ->join('f.documentSection', 's')->addSelect('s')
            ->where('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('d.name')
            ->getQuery()
            ->getResult();

        return $models;
    }
}
