<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Model\ProfileAssignmentRow;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AssignmentColorPalette;
use App\Service\CalendarMonthGridBuilder;
use App\Service\NonWorkingDayChecker;
use App\Service\TenantContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Calendario mensual: eventos de centro (según visibilidad — generales para
 * todos, restringidos según perfil o subperfil asignado) en una cuadrícula
 * mensual.
 */
#[AsLiveComponent]
class CalendarComponent extends AbstractCalendarComponent
{
    private const array GENERAL_EVENT_COLOR = ['bg' => 'bg-sky-100', 'text' => 'text-sky-800', 'border' => 'border-sky-300'];

    /** @var list<SchoolEvent>|null */
    private ?array $itemsCache = null;

    public function __construct(
        TenantContext $tenantContext,
        TranslatorInterface $translator,
        NonWorkingDayChecker $nonWorkingDayChecker,
        ClockInterface $clock,
        private readonly SchoolEventRepository $eventRepository,
        private readonly CalendarMonthGridBuilder $gridBuilder,
        private readonly AssignmentColorPalette $colorPalette,
    ) {
        parent::__construct($tenantContext, $translator, $nonWorkingDayChecker, $clock);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeks(): array
    {
        $centre       = $this->getTenantContext()->getSelectedCentre();
        $academicYear = $centre !== null ? $this->getTenantContext()->getViewYear($centre) : null;
        if ($centre === null || $academicYear === null) {
            return [];
        }

        $items = $this->getItemsForYear($centre, $academicYear);

        return $this->gridBuilder->build(
            $this->year,
            $this->month,
            $items,
            static fn (SchoolEvent $item): array => [
                'id'    => 'event-' . $item->getId()->toRfc4122(),
                'start' => $item->getDate(),
                'end'   => $item->getDate(),
            ],
            function (SchoolEvent $item): array {
                $firstRestriction = $item->getProfileRestrictions()->first();
                $color            = $item->isGeneral() || $firstRestriction === false
                    ? self::GENERAL_EVENT_COLOR
                    : $this->colorPalette->colorFor(ProfileAssignmentRow::keyFor($firstRestriction->getSpecificProfile(), $firstRestriction->getListItem()));

                return [
                    'label'   => $item->getStartTime()->format('H:i') . '–' . $item->getEndTime()->format('H:i') . ' ' . $item->getName(),
                    'details' => '',
                    'color'   => $color,
                    'icon'    => 'heroicons:megaphone',
                ];
            },
        );
    }

    /**
     * @return list<SchoolEvent>
     */
    private function getItemsForYear(EducationalCentre $centre, AcademicYear $academicYear): array
    {
        if ($this->itemsCache !== null) {
            return $this->itemsCache;
        }

        $user   = $this->getUser();
        $viewer = $user instanceof Teacher ? $user : null;

        if ($this->isGranted(EducationalCentreVoter::SECTION, $centre)) {
            $items = $this->eventRepository->findAllForAcademicYear($academicYear);
        } elseif ($viewer !== null) {
            $items = $this->eventRepository->findVisibleForTeacherInAcademicYear($viewer, $academicYear);
        } else {
            $items = [];
        }

        $this->itemsCache = $items;

        return $items;
    }
}
