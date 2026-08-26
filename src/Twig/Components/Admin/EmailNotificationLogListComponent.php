<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Pagination\Paginator;
use App\Repository\EmailNotificationLogRepository;
use App\Twig\Components\PaginatedListTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class EmailNotificationLogListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $eventKey = '';

    #[LiveProp(writable: true)]
    public string $status = '';

    #[LiveProp(writable: true)]
    public string $dateFrom = '';

    #[LiveProp(writable: true)]
    public string $dateTo = '';

    public function __construct(
        private readonly EmailNotificationLogRepository $logs,
        private readonly ClockInterface $clock,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
    }

    /** @return Paginator<EmailNotificationLog> */
    public function getPagination(): Paginator
    {
        return $this->paginate($this->logs->createFilteredQuery($this->centre, [
            'search'   => $this->search,
            'eventKey' => $this->eventKey,
            'status'   => $this->status,
            'dateFrom' => $this->dateFrom,
            'dateTo'   => $this->dateTo,
        ]));
    }

    /** @return list<string> */
    public function getDistinctEventKeys(): array
    {
        return $this->logs->findDistinctEventKeys($this->centre);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->eventKey !== '' || $this->status !== ''
            || $this->dateFrom !== '' || $this->dateTo !== '';
    }

    #[LiveAction]
    public function quickRange(#[LiveArg] string $range): void
    {
        $now = $this->clock->now();

        [$from, $to] = match ($range) {
            'last_24h'   => [$now->modify('-24 hours'), $now],
            'last_week'  => [$now->modify('-7 days'),   $now],
            'last_month' => [$now->modify('-30 days'),  $now],
            default      => [null, null],
        };

        $this->dateFrom = $from?->format('Y-m-d\TH:i') ?? '';
        $this->dateTo   = $to?->format('Y-m-d\TH:i') ?? '';
        $this->page     = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->search   = '';
        $this->eventKey = '';
        $this->status   = '';
        $this->dateFrom = '';
        $this->dateTo   = '';
        $this->page     = 1;
    }

    public function updatedEventKey(): void { $this->page = 1; }
    public function updatedStatus(): void   { $this->page = 1; }
    public function updatedDateFrom(): void { $this->page = 1; }
    public function updatedDateTo(): void   { $this->page = 1; }
}
