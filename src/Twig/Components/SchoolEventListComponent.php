<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\SchoolEvent;
use App\Model\ProfileAssignmentRow;
use App\Pagination\Paginator;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettingsInterface;
use App\Service\ProfileAssignmentRowBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SchoolEventListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $profileRowKey = '';

    public function __construct(
        private readonly SchoolEventRepository $events,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly TenantContext $tenantContext,
        private readonly AppSettingsInterface $appSettings,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->centre = $centre;
    }

    /** @return ProfileAssignmentRow[] */
    public function getAvailableProfileRows(): array
    {
        return $this->rowBuilder->buildActiveRows($this->centre);
    }

    /** @return Paginator<SchoolEvent> */
    public function getPagination(): Paginator
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromQuery($this->events->findNoneQuery(), 1, $this->pageSize());
        }

        return $this->paginate(
            $this->events->createFilteredQuery($year, trim($this->search), trim($this->profileRowKey)),
        );
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->profileRowKey !== '';
    }
}
