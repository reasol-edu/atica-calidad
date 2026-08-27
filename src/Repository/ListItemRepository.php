<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListItem>
 */
class ListItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListItem::class);
    }

    /** @return ListItem[] */
    public function findRootsByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('li')
            ->where('li.educationalCentre = :centre')
            ->andWhere('li.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('li.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return ListItem[] */
    public function findChildrenByParent(ListItem $parent): array
    {
        return $this->createQueryBuilder('li')
            ->where('li.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->orderBy('li.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?ListItem
    {
        $result = $this->createQueryBuilder('li')
            ->where('li.id = :id')
            ->andWhere('li.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ListItem ? $result : null;
    }

    public function nextRootPosition(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('li')
            ->select('COUNT(li.id)')
            ->where('li.educationalCentre = :centre')
            ->andWhere('li.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function nextChildPosition(ListItem $parent): int
    {
        return (int) $this->createQueryBuilder('li')
            ->select('COUNT(li.id)')
            ->where('li.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every leaf (childless) descendant of $root, in a stable pre-order walk
     * (siblings sorted by position). Single query for the whole centre, then
     * an in-memory tree walk — avoids recursive SQL, which isn't portable
     * across PostgreSQL/MySQL/SQLite.
     *
     * @return ListItem[]
     */
    public function findLeafDescendants(ListItem $root): array
    {
        $byParent = $this->groupChildrenByParent($root->getEducationalCentre());

        $leaves = [];
        $this->collectLeaves($root, $byParent, $leaves);

        return $leaves;
    }

    /**
     * @param array<string, ListItem[]> $byParent
     * @param ListItem[]                $leaves
     */
    private function collectLeaves(ListItem $node, array $byParent, array &$leaves): void
    {
        $children = $byParent[$node->getId()->toRfc4122()] ?? [];
        if ($children === []) {
            $leaves[] = $node;

            return;
        }

        foreach ($children as $child) {
            $this->collectLeaves($child, $byParent, $leaves);
        }
    }

    /** @return array<string, ListItem[]> keyed by parent UUID (RFC4122), root items under '' */
    private function groupChildrenByParent(EducationalCentre $centre): array
    {
        $all = $this->createQueryBuilder('li')
            ->where('li.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('li.position', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($all as $item) {
            $parentKey = $item->getParent()?->getId()->toRfc4122() ?? '';
            $map[$parentKey][] = $item;
        }

        return $map;
    }
}
