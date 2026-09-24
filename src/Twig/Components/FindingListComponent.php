<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingKind;
use App\Entity\FindingOrigin;
use App\Entity\FindingStatus;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\FindingRepository;
use App\Security\Voter\QualityVoter;
use App\Service\AppSettingsInterface;
use App\Service\SectionChoiceBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Every finding of the centre, for whoever sees them all (QualityVoter::VIEW_ALL): filters by
 * status (the open ones by default), kind, origin, process, year and text, as a paginated table
 * or as a board with a column per status of the open ones.
 */
#[AsLiveComponent]
class FindingListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    public const array BOARD_COLUMNS = [FindingStatus::Reported, FindingStatus::Analysis, FindingStatus::Execution, FindingStatus::Verification];

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true, url: true)]
    public string $status = 'open';

    #[LiveProp(writable: true, url: true)]
    public string $kind = '';

    #[LiveProp(writable: true, url: true)]
    public string $origin = '';

    #[LiveProp(writable: true, url: true)]
    public string $section = '';

    #[LiveProp(writable: true, url: true)]
    public string $year = '';

    #[LiveProp(writable: true, url: true)]
    public string $query = '';

    /** "table" or "board". */
    #[LiveProp(writable: true, url: true)]
    public string $view = 'table';

    public function __construct(
        private readonly FindingRepository $findings,
        private readonly SectionChoiceBuilder $sectionChoices,
        private readonly AppSettingsInterface $appSettings,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(QualityVoter::VIEW_ALL, $centre);
        $this->centre = $centre;
    }

    /** @return Paginator<Finding> */
    public function getPagination(): Paginator
    {
        return $this->paginate($this->findings->createFilteredQuery($this->centre, $this->filters()));
    }

    /** @return array<string, list<Finding>> the open findings matching the other filters, by status */
    public function getBoard(): array
    {
        $columns = [];
        foreach (self::BOARD_COLUMNS as $status) {
            $columns[$status->value] = [];
        }
        foreach ($this->findings->createFilteredQuery($this->centre, ['status' => 'open'] + $this->filters())->getResult() as $finding) {
            $columns[$finding->getStatus()->value][] = $finding;
        }

        return $columns;
    }

    #[LiveAction]
    public function setView(#[LiveArg] string $view): void
    {
        $this->view = $view === 'board' ? 'board' : 'table';
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->status = 'open';
        $this->kind   = $this->origin = $this->section = $this->year = $this->query = '';
        $this->page   = 1;
    }

    public function hasFilters(): bool
    {
        return $this->status !== 'open' || $this->kind !== '' || $this->origin !== '' || $this->section !== '' || $this->year !== '' || $this->query !== '';
    }

    /** @return list<FindingStatus> */
    public function getStatuses(): array
    {
        return FindingStatus::cases();
    }

    /** @return list<FindingKind> */
    public function getKinds(): array
    {
        return FindingKind::cases();
    }

    /** @return list<FindingOrigin> */
    public function getOrigins(): array
    {
        return FindingOrigin::cases();
    }

    /** @return list<int> */
    public function getYears(): array
    {
        return $this->findings->findYears($this->centre);
    }

    /** @return list<array{id: string, label: string, indented: string, depth: int}> */
    public function getSectionChoices(): array
    {
        $user = $this->getUser();

        return $user instanceof Teacher ? $this->sectionChoices->choices($user, $this->centre) : [];
    }

    public function updatedStatus(): void  { $this->page = 1; }
    public function updatedKind(): void    { $this->page = 1; }
    public function updatedOrigin(): void  { $this->page = 1; }
    public function updatedSection(): void { $this->page = 1; }
    public function updatedYear(): void    { $this->page = 1; }

    /** @return array{status?: string, kind?: string, origin?: string, section?: string, year?: string, query?: string} */
    private function filters(): array
    {
        return [
            'status'  => $this->view === 'board' ? 'open' : $this->status,
            'kind'    => $this->kind,
            'origin'  => $this->origin,
            'section' => $this->section,
            'year'    => $this->year,
            'query'   => $this->query,
        ];
    }
}
