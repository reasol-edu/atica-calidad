<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<SchoolEvent>
 */
class SchoolEventRepository extends ServiceEntityRepository
{
    use PreloadsCollections;

    public function __construct(ManagerRegistry $registry, private readonly SpecificProfileAssignmentRepository $assignments)
    {
        parent::__construct($registry, SchoolEvent::class);
    }

    /** @return Query<null, SchoolEvent> */
    public function findNoneQuery(): Query
    {
        return $this->createQueryBuilder('se')
            ->where('1 = 0')
            ->getQuery();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?SchoolEvent
    {
        $result = $this->createQueryBuilder('se')
            ->where('se.academicYear = :year')
            ->andWhere('se.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SchoolEvent ? $result : null;
    }

    /** @return list<SchoolEvent> */
    public function findAllForAcademicYearAndDate(AcademicYear $year, \DateTimeImmutable $date): array
    {
        return $this->baseQueryBuilder($year)
            ->andWhere('se.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SchoolEvent> */
    public function findVisibleForTeacherAndDate(Teacher $viewer, AcademicYear $year, \DateTimeImmutable $date): array
    {
        return $this->visibleForTeacherQueryBuilder($viewer, $year)
            ->andWhere('se.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SchoolEvent> */
    public function findAllForAcademicYear(AcademicYear $year): array
    {
        /** @var list<SchoolEvent> $events */
        $events = $this->baseQueryBuilder($year)->getQuery()->getResult();

        return $this->withProfileRestrictions($events);
    }

    /** @return list<SchoolEvent> */
    public function findVisibleForTeacherInAcademicYear(Teacher $viewer, AcademicYear $year): array
    {
        /** @var list<SchoolEvent> $events */
        $events = $this->visibleForTeacherQueryBuilder($viewer, $year)->getQuery()->getResult();

        return $this->withProfileRestrictions($events);
    }

    /**
     * $events with their profile restrictions loaded (the calendar reads them for each event:
     * its colour and who it's for), in one query instead of one per event.
     *
     * @param list<SchoolEvent> $events
     *
     * @return list<SchoolEvent>
     */
    private function withProfileRestrictions(array $events): array
    {
        $this->preloadCollection(SchoolEvent::class, $events, 'profileRestrictions', ['specificProfile', 'listItem']);

        return $events;
    }

    /**
     * @param string $profileRowKey a ProfileAssignmentRow::key() — either a specific-profile UUID
     *                              (direct profile) or "{profileId}:{listItemId}" (a subprofile)
     *
     * @return Query<null, SchoolEvent>
     */
    public function createFilteredQuery(AcademicYear $year, string $search = '', string $profileRowKey = ''): Query
    {
        $qb = $this->baseQueryBuilder($year)
            ->orderBy('se.date', SortDirection::Descending)
            ->addOrderBy('se.startTime');

        if ($profileRowKey !== '') {
            [$profileId, $listItemId] = array_pad(explode(':', $profileRowKey, 2), 2, null);

            $qb->join('se.profileRestrictions', 'fr')
                ->andWhere('fr.specificProfile = :frProfile')
                ->setParameter('frProfile', $profileId, 'uuid');

            if ($listItemId === null) {
                $qb->andWhere('fr.listItem IS NULL');
            } else {
                $qb->andWhere('fr.listItem = :frListItem')
                    ->setParameter('frListItem', $listItemId, 'uuid');
            }
        }

        if ($search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(se.name)) LIKE UNACCENT(LOWER(:search))',
                    'UNACCENT(LOWER(se.description)) LIKE UNACCENT(LOWER(:search))',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery();
    }

    private function baseQueryBuilder(AcademicYear $year): QueryBuilder
    {
        return $this->createQueryBuilder('se')
            ->distinct()
            ->where('se.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid');
    }

    private function visibleForTeacherQueryBuilder(Teacher $viewer, AcademicYear $year): QueryBuilder
    {
        $qb = $this->baseQueryBuilder($year);
        $qb->leftJoin('se.profileRestrictions', 'sep');

        // A restriction is the viewer's when it is on a profile they hold and either names no list
        // item (the whole profile — or the plain profile itself) or names one that covers a leaf
        // they hold: that leaf itself or an intermediate node above it (see
        // SpecificProfileAssignmentRepository::findCoveringItemIdsByTeacher()).
        $conditions = ['se.general = true'];
        $i          = 0;
        foreach ($this->assignments->findCoveringItemIdsByTeacher($viewer) as $profileId => $coveringIds) {
            $qb->setParameter("profile{$i}", $profileId, 'uuid');
            $itemMatch = ['sep.listItem IS NULL'];
            if ($coveringIds !== []) {
                $placeholders = [];
                foreach ($coveringIds as $j => $itemId) {
                    $placeholders[] = ":item{$i}_{$j}";
                    $qb->setParameter("item{$i}_{$j}", $itemId, 'uuid');
                }
                $itemMatch[] = 'sep.listItem IN (' . implode(', ', $placeholders) . ')';
            }
            $conditions[] = "(sep.specificProfile = :profile{$i} AND (" . implode(' OR ', $itemMatch) . '))';
            ++$i;
        }
        $qb->andWhere(implode(' OR ', $conditions));

        return $qb;
    }
}
