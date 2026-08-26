<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Service\NonWorkingDayChecker;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Shared month-navigation behaviour for the calendar's Live Components
 * (sanctions and absences): current year/month state, prev/next/today
 * actions, and the today/current-month helpers used by the shared grid
 * template. Subclasses only need to provide getWeeks().
 */
abstract class AbstractCalendarComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public int $year = 0;

    #[LiveProp(writable: true)]
    public int $month = 0;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly ClockInterface $clock,
    ) {}

    public function mount(): void
    {
        $today = $this->clock->now();
        if ($this->year < 2000 || $this->year > 2100) {
            $this->year = (int) $today->format('Y');
        }
        if ($this->month < 1 || $this->month > 12) {
            $this->month = (int) $today->format('n');
        }
    }

    #[LiveAction]
    public function previousMonth(): void
    {
        $d = $this->clock->now()->setDate($this->year, $this->month, 1)->modify('-1 month');
        $this->year  = (int) $d->format('Y');
        $this->month = (int) $d->format('n');
    }

    #[LiveAction]
    public function nextMonth(): void
    {
        $d = $this->clock->now()->setDate($this->year, $this->month, 1)->modify('+1 month');
        $this->year  = (int) $d->format('Y');
        $this->month = (int) $d->format('n');
    }

    #[LiveAction]
    public function goToday(): void
    {
        $today       = $this->clock->now();
        $this->year  = (int) $today->format('Y');
        $this->month = (int) $today->format('n');
    }

    public function getCentre(): ?EducationalCentre
    {
        return $this->tenantContext->getSelectedCentre();
    }

    public function getMonthLabel(): string
    {
        return $this->translator->trans('month.' . $this->month, [], 'calendar') . ' ' . $this->year;
    }

    public function isToday(\DateTimeImmutable $day): bool
    {
        return $day->format('Y-m-d') === $this->clock->now()->format('Y-m-d');
    }

    public function isCurrentMonth(\DateTimeImmutable $day): bool
    {
        return (int) $day->format('n') === $this->month
            && (int) $day->format('Y') === $this->year;
    }

    public function isNonWorkingDay(\DateTimeImmutable $day): bool
    {
        $centre       = $this->tenantContext->getSelectedCentre();
        $academicYear = $centre !== null ? $this->tenantContext->getViewYear($centre) : null;
        if ($academicYear === null) {
            return false;
        }

        return $this->nonWorkingDayChecker->isNonWorkingDay($academicYear, $day);
    }

    public function nonWorkingDayDescription(\DateTimeImmutable $day): ?string
    {
        $centre       = $this->tenantContext->getSelectedCentre();
        $academicYear = $centre !== null ? $this->tenantContext->getViewYear($centre) : null;
        if ($academicYear === null) {
            return null;
        }

        return $this->nonWorkingDayChecker->descriptionFor($academicYear, $day);
    }

    /**
     * @return list<array<string, mixed>>
     */
    abstract public function getWeeks(): array;

    protected function getTenantContext(): TenantContext
    {
        return $this->tenantContext;
    }
}
