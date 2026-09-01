<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
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

    /**
     * Documents matching $query by their own name or their folder's name — for the activity
     * "related documents" picker, which the user explicitly wants searchable by either (unlike
     * searchByCentre(), which only matches the document's own name/uploader/profile and backs
     * unrelated global search UIs that shouldn't change behavior).
     *
     * @return Document[]
     */
    public function searchByCentreOrFolderName(EducationalCentre $centre, string $query, int $limit = 8): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.folder', 'f')
            ->join('f.documentSection', 's')
            ->where('s.educationalCentre = :centre')
            ->andWhere('LOWER(d.name) LIKE LOWER(:query) OR LOWER(f.name) LIKE LOWER(:query)')
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

    /**
     * Resolves an activity submission slot (see ActivitySubmissionSlot) to its Document, if
     * anyone has already uploaded one — matched by identity, including NULL (a plain,
     * non-list-associated profile has $listItem === null, which must match exactly, not "any").
     * $firstUploader, when given (Individual submission scope), additionally requires the
     * document's own first (version 1) revision to have been uploaded by that exact teacher — two
     * different teachers submitting under the same profile/subprofile/name are two different
     * Documents, distinguished only by who created each one first.
     */
    public function findOneByFolderProfileListItemNameAndFirstUploader(
        Folder $folder,
        ?SpecificProfile $profile,
        ?ListItem $listItem,
        string $name,
        ?Teacher $firstUploader,
    ): ?Document {
        $qb = $this->createQueryBuilder('d')
            ->where('d.folder = :folder')
            ->andWhere('d.name = :name')
            ->setParameter('folder', $folder->getId(), 'uuid')
            ->setParameter('name', $name);

        if ($profile !== null) {
            $qb->andWhere('d.uploadProfile = :profile')->setParameter('profile', $profile->getId(), 'uuid');
        } else {
            $qb->andWhere('d.uploadProfile IS NULL');
        }

        if ($listItem !== null) {
            $qb->andWhere('d.uploadListItem = :listItem')->setParameter('listItem', $listItem->getId(), 'uuid');
        } else {
            $qb->andWhere('d.uploadListItem IS NULL');
        }

        if ($firstUploader !== null) {
            $qb->andWhere(
                'EXISTS ('
                . 'SELECT 1 FROM App\Entity\DocumentRevision r'
                . ' WHERE r.document = d AND r.version = 1 AND r.uploadedBy = :firstUploader'
                . ')'
            )->setParameter('firstUploader', $firstUploader->getId(), 'uuid');
        }

        $result = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $result instanceof Document ? $result : null;
    }

    /**
     * Documents that are activity submissions (their folder backs an Activity) matching $query —
     * used by the Actividades section's own search bar and by ⌘K's `documents` group (to decide
     * whether a hit should link to the activity instead of the document tree).
     *
     * @return list<Document>
     */
    public function searchActivitySubmissionsByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.folder', 'f')
            ->join('f.activity', 'a')
            ->join('f.documentSection', 's')
            ->where('s.educationalCentre = :centre')
            ->andWhere('LOWER(d.name) LIKE LOWER(:query)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('d.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
