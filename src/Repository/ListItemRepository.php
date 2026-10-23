<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ServiceEntityRepository<ListItem>
 */
class ListItemRepository extends ServiceEntityRepository implements ResetInterface
{
    /**
     * Per-centre children-by-parent map used by findLeafDescendants(), memoized for the life of
     * the request. findLeafDescendants() re-fetches and re-groups the WHOLE centre's list-item
     * tree every time it's called, regardless of which root it's asked about — a caller that walks
     * many different roots of the same centre in one request (ProfileAssignmentRowBuilder over
     * every list-associated profile, ActivitySubmissionSlotBuilder over every activity's folder
     * rows) ends up re-running this identical, param-for-param query dozens of times. Caches
     * ListItem entities, so — like ProfileAssignmentRowBuilder — implements ResetInterface to clear
     * at the same point Doctrine resets its identity map, rather than risk a stale, detached entity.
     *
     * @var array<string, array<string, ListItem[]>>
     */
    private array $byParentCache = [];

    /** @var array<string, array<string, string>> per centre: item id => parent id ('' for a root), see findSelfAndAncestorIds() */
    private array $parentOfCache = [];

    /** @var array<string, list<string>> item id => ids of the leaves it covers, see findLeafIdsUnder() */
    private array $leafIdsCache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListItem::class);
    }

    public function reset(): void
    {
        $this->byParentCache = [];
        $this->parentOfCache = [];
        $this->leafIdsCache  = [];
    }

    /** @return ListItem[] */
    public function findRootsByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('li')
            ->where('li.educationalCentre = :centre')
            ->andWhere('li.parent IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('li.position')
            ->getQuery()
            ->getResult();
    }

    /** @return ListItem[] */
    public function findChildrenByParent(ListItem $parent): array
    {
        return $this->createQueryBuilder('li')
            ->where('li.parent = :parent')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->orderBy('li.position')
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
     * The whole centre's list-item tree in one query, ordered so that a stable pre-order walk can
     * be rebuilt in memory (siblings sorted by position).
     *
     * @return ListItem[]
     */
    public function findAllByCentre(EducationalCentre $centre): array
    {
        return $this->createQueryBuilder('li')
            ->leftJoin('li.profileAssociations', 'pa')
            ->addSelect('pa')
            ->where('li.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('li.position')
            ->getQuery()
            ->getResult();
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

        $leaves  = [];
        $visited = [];
        $this->collectLeaves($root, $byParent, $leaves, $visited);

        return $leaves;
    }

    /**
     * The ids (RFC4122) of the leaves a restriction on $item covers: $item's own leaf descendants,
     * or $item itself when it is a leaf. What lets a restriction on an intermediate node ("FP")
     * stand for every leaf under it — the one rule behind matching a teacher's assignments (always
     * to leaves) against a restriction, wherever it is asked.
     *
     * @return list<string>
     */
    public function findLeafIdsUnder(ListItem $item): array
    {
        // Asked once per restriction by every access check of a page: walk the tree once per item.
        return $this->leafIdsCache[$item->getId()->toRfc4122()] ??= array_values(array_map(
            static fn (ListItem $leaf): string => $leaf->getId()->toRfc4122(),
            $this->findLeafDescendants($item),
        ));
    }

    /**
     * $item and every ancestor of it, nearest first — the nodes a restriction would have to be on
     * to cover the leaf $item. In memory over the centre's tree (no query per level).
     *
     * @return list<string> RFC4122 ids
     */
    public function findSelfAndAncestorIds(ListItem $item): array
    {
        $centreKey = $item->getEducationalCentre()->getId()->toRfc4122();
        if (!isset($this->parentOfCache[$centreKey])) {
            $parentOf = [];
            foreach ($this->groupChildrenByParent($item->getEducationalCentre()) as $parentKey => $children) {
                foreach ($children as $child) {
                    $parentOf[$child->getId()->toRfc4122()] = $parentKey;
                }
            }
            $this->parentOfCache[$centreKey] = $parentOf;
        }
        $parentOf = $this->parentOfCache[$centreKey];

        $ids = [];
        for ($id = $item->getId()->toRfc4122(); $id !== '' && !\in_array($id, $ids, true); $id = $parentOf[$id] ?? '') {
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * $visited stops a walk that comes back to a node: a parent loop cannot be built through the
     * application (ListItem::setParent() refuses it), but corrupted data must not recurse forever.
     *
     * @param array<string, ListItem[]> $byParent
     * @param ListItem[]                $leaves
     * @param array<string, true>       $visited
     */
    private function collectLeaves(ListItem $node, array $byParent, array &$leaves, array &$visited): void
    {
        $id = $node->getId()->toRfc4122();
        if (isset($visited[$id])) {
            return;
        }
        $visited[$id] = true;

        $children = $byParent[$id] ?? [];
        if ($children === []) {
            $leaves[] = $node;

            return;
        }

        foreach ($children as $child) {
            $this->collectLeaves($child, $byParent, $leaves, $visited);
        }
    }

    /**
     * $root and every descendant, in a pre-order walk (root first, then each child's own subtree,
     * siblings ordered by position) — the whole branch a caller deletes as one unit when removing an
     * item that has children, not just a leaf. Single query for the whole centre, then an in-memory
     * tree walk, like findLeafDescendants().
     *
     * @return ListItem[]
     */
    public function findSubtree(ListItem $root): array
    {
        $byParent = $this->groupChildrenByParent($root->getEducationalCentre());

        $subtree = [];
        $visited = [];
        $this->collectSubtree($root, $byParent, $subtree, $visited);

        return $subtree;
    }

    /**
     * @param array<string, ListItem[]> $byParent
     * @param ListItem[]                $subtree
     * @param array<string, true>       $visited see collectLeaves()
     */
    private function collectSubtree(ListItem $node, array $byParent, array &$subtree, array &$visited): void
    {
        $id = $node->getId()->toRfc4122();
        if (isset($visited[$id])) {
            return;
        }
        $visited[$id] = true;

        $subtree[] = $node;
        foreach ($byParent[$id] ?? [] as $child) {
            $this->collectSubtree($child, $byParent, $subtree, $visited);
        }
    }

    /** @return array<string, ListItem[]> keyed by parent UUID (RFC4122), root items under '' */
    private function groupChildrenByParent(EducationalCentre $centre): array
    {
        $key = $centre->getId()->toRfc4122();
        if (isset($this->byParentCache[$key])) {
            return $this->byParentCache[$key];
        }

        // With each item's profile associations in the same query: walking the tree asks every leaf
        // (and its ancestors) for them — see ListItem::getEffectiveProfileAssociations().
        $all = $this->createQueryBuilder('li')
            ->leftJoin('li.profileAssociations', 'pa')
            ->addSelect('pa')
            ->where('li.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('li.position')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($all as $item) {
            $parentKey = $item->getParent()?->getId()->toRfc4122() ?? '';
            $map[$parentKey][] = $item;
        }

        return $this->byParentCache[$key] = $map;
    }
}
