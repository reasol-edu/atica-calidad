<?php

declare(strict_types=1);

namespace App\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * Lightweight paginator. Built either from a Doctrine Query
 * (fromQuery) or from already materialized and sliced rows (fromArray).
 *
 * @template T
 */
final class Paginator
{
    /** @var array<array-key, T>|null */
    private ?array $materializedItems = null;

    /**
     * @param iterable<array-key, T> $items current page's items
     */
    private function __construct(
        private readonly iterable $items,
        private readonly int $totalItems,
        private readonly int $currentPage,
        private readonly int $pageSize,
    ) {}

    /**
     * @template U of object
     * @param Query<null, U> $query
     * @return self<U>
     */
    public static function fromQuery(Query $query, int $currentPage, int $pageSize): self
    {
        $query
            ->setFirstResult(max(0, ($currentPage - 1) * $pageSize))
            ->setMaxResults($pageSize);

        // fetchJoinCollection defaults to true, which routes the paginator through Doctrine's
        // LimitSubqueryOutputWalker even for queries with no to-many fetch-join at all. That
        // walker has a known incompatibility with ORDER BY on an embedded/embeddable field (e.g.
        // Teacher::$name.lastName): the id-only subquery it builds silently returns zero rows,
        // so getItems() comes back empty while the separate COUNT query still reports the right
        // total. None of this app's paginated queries addSelect() a to-many association (the only
        // case fetchJoinCollection=true actually matters for), so false is correct everywhere.
        /** @var DoctrinePaginator<U> $paginator */
        $paginator = new DoctrinePaginator($query, false);

        return new self($paginator, count($paginator), $currentPage, $pageSize);
    }

    /**
     * @template U
     * @param list<U> $rows current page's items, already sliced
     * @return self<U>
     */
    public static function fromArray(array $rows, int $totalItems, int $currentPage, int $pageSize): self
    {
        return new self($rows, $totalItems, $currentPage, $pageSize);
    }

    /**
     * Materializes and caches the underlying items on first access, so that calling
     * this more than once (e.g. to render the page and separately to batch-load
     * related data for it) only runs the underlying query once.
     *
     * @return array<array-key, T>
     */
    public function getItems(): array
    {
        return $this->materializedItems ??= (is_array($this->items) ? $this->items : iterator_to_array($this->items));
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function getTotalPages(): int
    {
        return max(1, (int) ceil($this->totalItems / $this->pageSize));
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getFirstItemIndex(): int
    {
        return $this->totalItems === 0 ? 0 : ($this->currentPage - 1) * $this->pageSize + 1;
    }

    public function getLastItemIndex(): int
    {
        return min($this->currentPage * $this->pageSize, $this->totalItems);
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getTotalPages();
    }

    public function getPreviousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function getNextPage(): int
    {
        return min($this->getTotalPages(), $this->currentPage + 1);
    }

    /**
     * Returns the sequence of pages to show in the navigation bar.
     * Null values represent an "…" separator.
     *
     * @return array<int, int|null>
     */
    public function getPageRange(): array
    {
        $total   = $this->getTotalPages();
        $current = $this->currentPage;

        if ($total <= 1) {
            return [1];
        }

        $delta = 2; // pages to show around the current one

        /** @var list<int> $inner */
        $inner = [];
        for ($i = max(2, $current - $delta); $i <= min($total - 1, $current + $delta); $i++) {
            $inner[] = $i;
        }

        $pages = [1];

        if ($inner !== [] && $inner[0] > 2) {
            $pages[] = null; // ellipsis inicial
        }

        foreach ($inner as $page) {
            $pages[] = $page;
        }

        $lastInner = count($inner) > 0 ? $inner[count($inner) - 1] : null;
        if ($lastInner !== null && $lastInner < $total - 1) {
            $pages[] = null; // ellipsis final
        }

        $pages[] = $total;

        return $pages;
    }
}
