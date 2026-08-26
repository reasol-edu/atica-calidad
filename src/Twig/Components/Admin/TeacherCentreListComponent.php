<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\TenantContext;
use App\Twig\Components\PaginatedListTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class TeacherCentreListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->centre = $centre;
    }

    /** @return Paginator<Teacher> */
    public function getPagination(): Paginator
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromQuery($this->teachers->findNoneQuery(), 1, self::PAGE_SIZE);
        }

        return $this->paginate(
            $this->teachers->createByAcademicYearFilteredQuery(
                $year,
                trim($this->search),
            ),
        );
    }
}
