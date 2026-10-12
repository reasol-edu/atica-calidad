<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\Folder;
use App\Entity\SchoolEvent;

/**
 * For a repository that returns a list of entities every caller then walks reading one of their
 * collections: initializes that collection for the whole list with one query, instead of the one
 * lazy query per entity that reading it costs otherwise — which grows with the list.
 *
 * Joining the collections into the list's own query isn't an option when there are several (each
 * would multiply the rows of the others) or it's paged; this is one extra query per collection,
 * whatever the number of entities.
 */
trait PreloadsCollections
{
    /**
     * @param class-string                                            $class  the owners' entity
     * @param list<Activity>|list<Folder>|list<SchoolEvent>|list<Document> $owners
     * @param list<string>                                            $joins  associations of the collection's elements to bring along
     */
    private function preloadCollection(string $class, array $owners, string $field, array $joins = []): void
    {
        foreach (array_chunk($owners, 300) as $chunk) {
            // A left join: an owner with nothing in the collection must come out initialized (empty) too.
            $qb = $this->getEntityManager()->createQueryBuilder()
                ->select('o', 'c')
                ->from($class, 'o')
                ->leftJoin('o.' . $field, 'c');
            foreach ($joins as $i => $join) {
                $qb->leftJoin('c.' . $join, 'j' . $i)->addSelect('j' . $i);
            }
            $placeholders = [];
            foreach ($chunk as $i => $owner) {
                $placeholders[] = ':owner' . $i;
                $qb->setParameter('owner' . $i, $owner->getId(), 'uuid');
            }
            $qb->where('o.id IN (' . implode(', ', $placeholders) . ')')->getQuery()->getResult();
        }
    }
}
