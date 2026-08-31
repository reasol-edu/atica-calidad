<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\TeacherRepository;
use App\Service\AppSettingsInterface;
use App\Twig\Components\PaginatedListTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class TeacherListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp(writable: true)]
    public string $search = '';

    public function __construct(
        private readonly TeacherRepository $teachers,
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

    /** @return Paginator<Teacher> */
    public function getPagination(): Paginator
    {
        return $this->paginate(
            $this->teachers->createFilteredOrderedByNameQuery(trim($this->search)),
        );
    }
}
