<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /** @return list<Document> */
    public function findByFolder(Folder $folder): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.folder = :folder')
            ->setParameter('folder', $folder->getId(), 'uuid')
            ->orderBy('d.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndFolder(string $id, Folder $folder): ?Document
    {
        $result = $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.folder = :folder')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('folder', $folder->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Document ? $result : null;
    }

    /** Looked up by bare id; callers must verify ownership. */
    public function findById(string $id): ?Document
    {
        $result = $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Document ? $result : null;
    }

    /**
     * Documents matching $query anywhere in the centre's tree — access filtering (folder
     * visibility, ancestor section visibility) is the caller's responsibility, same as
     * DocumentTreeAccessChecker is applied on top of findByFolder()/getVisibleFolders() elsewhere.
     * Matches the document's own name, its upload profile's name, or the active revision's
     * uploader's name — not just the document name — so searching "Tutor/a" or a teacher's name
     * also surfaces documents tagged/uploaded that way.
     *
     * @return list<Document>
     */
    public function searchByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.folder', 'f')
            ->join('f.documentSection', 's')
            ->leftJoin('d.uploadProfile', 'p')
            ->leftJoin('d.activeRevision', 'rev')
            ->leftJoin('rev.uploadedBy', 'u')
            ->where('s.educationalCentre = :centre')
            ->andWhere(
                'LOWER(d.name) LIKE LOWER(:query)'
                . ' OR LOWER(p.name) LIKE LOWER(:query)'
                . ' OR LOWER(u.name.firstName) LIKE LOWER(:query)'
                . ' OR LOWER(u.name.lastName) LIKE LOWER(:query)'
            )
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('d.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function nextPosition(Folder $folder): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.folder = :folder')
            ->setParameter('folder', $folder->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
