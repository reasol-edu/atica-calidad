<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Pagination\Paginator;
use App\Repository\ActivityLogRepository;
use App\Repository\EducationalCentreRepository;
use App\Service\AppSettingsInterface;
use App\Twig\Components\PaginatedListTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class ActivityLogListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp(writable: true)]
    public string $dateFrom = '';

    #[LiveProp(writable: true)]
    public string $dateTo = '';

    #[LiveProp(writable: true)]
    public string $userQuery = '';

    #[LiveProp(writable: true)]
    public string $centreId = '';

    #[LiveProp(writable: true)]
    public string $actionType = '';

    #[LiveProp(writable: true)]
    public string $sort = 'createdAt';

    #[LiveProp(writable: true)]
    public string $sortDir = 'desc';

    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly EducationalCentreRepository $centres,
        private readonly AppSettingsInterface $appSettings,
        private readonly ClockInterface $clock,
    ) {}

    public function mount(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    /** @return Paginator<ActivityLog> */
    public function getPagination(): Paginator
    {
        return $this->paginate($this->logs->createFilteredQuery([
            'dateFrom'   => $this->dateFrom,
            'dateTo'     => $this->dateTo,
            'userQuery'  => $this->userQuery,
            'centreId'   => $this->centreId,
            'actionType' => $this->actionType,
            'sort'       => $this->sort,
            'sortDir'    => $this->sortDir,
        ]));
    }

    /** @return EducationalCentre[] */
    public function getCentres(): array
    {
        return $this->centres->findAllOrderedByName();
    }

    /** @return list<string> */
    public function getDistinctActionTypes(): array
    {
        return $this->logs->findDistinctActionTypes();
    }

    public function hasActiveFilters(): bool
    {
        return $this->dateFrom !== '' || $this->dateTo !== '' || $this->userQuery !== ''
            || $this->centreId !== '' || $this->actionType !== '';
    }

    #[LiveAction]
    public function sortBy(#[LiveArg] string $column): void
    {
        if ($this->sort === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort    = $column;
            $this->sortDir = 'desc';
        }
        $this->page = 1;
    }

    #[LiveAction]
    public function quickRange(#[LiveArg] string $range): void
    {
        $now = $this->clock->now();

        [$from, $to] = match ($range) {
            'last_hour'  => [$now->modify('-1 hour'),   $now],
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
        $this->dateFrom   = '';
        $this->dateTo     = '';
        $this->userQuery  = '';
        $this->centreId   = '';
        $this->actionType = '';
        $this->page       = 1;
    }

    public function updatedCentreId(): void   { $this->page = 1; }
    public function updatedActionType(): void { $this->page = 1; }
    public function updatedDateFrom(): void   { $this->page = 1; }
    public function updatedDateTo(): void     { $this->page = 1; }
}
