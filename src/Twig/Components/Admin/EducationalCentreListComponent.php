<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Pagination\Paginator;
use App\Repository\EducationalCentreRepository;
use App\Service\AppSettingsInterface;
use App\Twig\Components\PaginatedListTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class EducationalCentreListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $sort = '';

    #[LiveProp(writable: true)]
    public string $sortDir = 'asc';

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly AppSettingsInterface $appSettings,
    ) {}

    public function mount(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    /** @return Paginator<EducationalCentre> */
    public function getPagination(): Paginator
    {
        return $this->paginate(
            $this->centres->createAllWithActiveYearFilteredQuery(trim($this->search), $this->sort, $this->sortDir),
        );
    }

    #[LiveAction]
    public function sortBy(#[LiveArg] string $column): void
    {
        if ($this->sort === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->sortDir = 'asc';
        }
        $this->page = 1;
    }
}
