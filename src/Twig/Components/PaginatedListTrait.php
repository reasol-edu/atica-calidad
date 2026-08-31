<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Pagination\Paginator;
use Doctrine\ORM\Query;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Shared page-navigation state for *ListComponent Live Components. Every class using this trait
 * must inject AppSettingsInterface as $this->appSettings (constructor-promoted, like every other
 * dependency here) — page size is the teacher-scoped 'page.size' setting, falling back to
 * FALLBACK_PAGE_SIZE only if that setting can't be resolved (e.g. its definition isn't seeded yet).
 */
trait PaginatedListTrait
{
    private const int FALLBACK_PAGE_SIZE = 20;

    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveAction]
    public function setPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
    }

    #[LiveAction]
    public function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @template T of object
     * @param Query<null, T> $query
     * @return Paginator<T>
     */
    private function paginate(Query $query): Paginator
    {
        return Paginator::fromQuery($query, max(1, $this->page), $this->pageSize());
    }

    private function pageSize(): int
    {
        $configured = $this->appSettings->getInt('page.size');

        return $configured > 0 ? $configured : self::FALLBACK_PAGE_SIZE;
    }
}
