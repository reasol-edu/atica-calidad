<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Folder>
 */
class FolderRepository extends ServiceEntityRepository
{
    use PreloadsCollections;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    /**
     * The section's folders, in position order — with the backing activity (an inverse OneToOne,
     * which would cost one query per folder) and the profile lists the page checks for each one
     * (who sees it, manages it, uploads to it, reviews it) already loaded.
     *
     * @return list<Folder>
     */
    public function findBySection(DocumentSection $section): array
    {
        /** @var list<Folder> $folders */
        $folders = $this->createQueryBuilder('f')
            ->leftJoin('f.activity', 'a')->addSelect('a')
            ->where('f.documentSection = :section')
            ->setParameter('section', $section->getId(), 'uuid')
            ->orderBy('f.position')
            ->getQuery()
            ->getResult();

        foreach (['visibilityProfiles', 'responsibleProfiles', 'uploadProfiles', 'reviewProfiles'] as $field) {
            $this->preloadCollection(Folder::class, $folders, $field, ['specificProfile', 'listItem']);
        }

        return $folders;
    }

    /**
     * Every folder of the centre's tree, with the backing activity and responsible profiles already
     * loaded (the inverse OneToOne $activity would otherwise cost one query per folder), in
     * position order — grouped by section by the caller.
     *
     * @return list<Folder>
     */
    public function findAllByCentreWithResponsibles(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.documentSection', 's')
            ->leftJoin('f.activity', 'a')->addSelect('a')
            ->leftJoin('f.responsibleProfiles', 'rp')->addSelect('rp')
            ->leftJoin('rp.specificProfile', 'sp')->addSelect('sp')
            ->leftJoin('rp.listItem', 'li')->addSelect('li')
            ->where('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('f.position')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every folder of the centre's tree with its review profiles (and their profile and subprofile)
     * and its backing activity already loaded — for walking all of them asking who reviews, which
     * would otherwise cost a few queries per folder.
     *
     * @return list<Folder>
     */
    public function findAllByCentreWithReviewProfiles(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.documentSection', 's')
            ->leftJoin('f.activity', 'a')->addSelect('a')
            ->leftJoin('f.reviewProfiles', 'rv')->addSelect('rv')
            ->leftJoin('rv.specificProfile', 'rvp')->addSelect('rvp')
            ->leftJoin('rv.listItem', 'rvl')->addSelect('rvl')
            ->where('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('f.name')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Folder> whose name matches $query anywhere in the centre's tree, ordered by name */
    public function searchByCentre(EducationalCentre $centre, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.documentSection', 's')
            ->where('s.educationalCentre = :centre')
            ->andWhere('UNACCENT(LOWER(f.name)) LIKE UNACCENT(LOWER(:query))')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('f.name')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Looked up by bare id (the section/centre isn't known ahead of time from the URL); callers must verify ownership. */
    public function findById(string $id): ?Folder
    {
        $result = $this->createQueryBuilder('f')
            ->where('f.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Folder ? $result : null;
    }

    public function findByIdAndSection(string $id, DocumentSection $section): ?Folder
    {
        $result = $this->createQueryBuilder('f')
            ->where('f.id = :id')
            ->andWhere('f.documentSection = :section')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('section', $section->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Folder ? $result : null;
    }

    public function nextPosition(DocumentSection $section): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.documentSection = :section')
            ->setParameter('section', $section->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Folder> every folder in the centre, ordered by name — populates the activity-folder-linking picker. */
    public function findAllByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.documentSection', 's')
            ->where('s.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('f.name')
            ->getQuery()
            ->getResult();
    }
}
