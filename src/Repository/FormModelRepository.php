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
     * Which of $documents are models, in one query (the pending reviews tell a model's revision from any other document's).
     *
     * @param list<Document> $documents
     *
     * @return array<string, true> by the document's id (RFC 4122)
     */
    public function documentIdsWithModel(array $documents): array
    {
        if ($documents === []) {
            return [];
        }
        // One typed parameter per document (as SpecificProfileAssignmentRepository does): a plain list would be bound as text, and the
        // ids are binary in SQLite and MySQL.
        $qb           = $this->createQueryBuilder('m');
        $placeholders = [];
        foreach ($documents as $i => $document) {
            $placeholders[] = ":document{$i}";
            $qb->setParameter("document{$i}", $document->getId(), 'uuid');
        }
        /** @var list<FormModel> $models */
        $models = $qb
            ->where('m.document IN (' . implode(', ', $placeholders) . ')')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($models as $model) {
            $ids[$model->getDocument()->getId()->toRfc4122()] = true;
        }

        return $ids;
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
            // What the list shows of each model (in force, pending) comes with the same query, not one or two more per model.
            ->leftJoin('d.revisions', 'rv')->addSelect('rv')
            ->leftJoin('d.activeRevision', 'ar')->addSelect('ar')
            ->where('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('d.name')
            ->getQuery()
            ->getResult();

        return $models;
    }
}
