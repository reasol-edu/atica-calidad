<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Model\ActivityDeadlineOccurrence;
use App\Model\ProfileAssignmentRow;
use App\Repository\ActivityRepository;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityDeadlineChecker;
use App\Service\AssignmentColorPalette;
use App\Service\CalendarMonthGridBuilder;
use App\Service\NonWorkingDayChecker;
use App\Service\TenantContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Monthly calendar: centre events (by visibility — general for everyone, restricted
 * by assigned profile or subprofile) plus, for each teacher, their own activity deadlines
 * (those where they hold an upload profile — never "all of the centre's", not even as an
 * admin: see ActivityCompletionChecker::getMyOwnedObligations()) in a monthly grid.
 */
#[AsLiveComponent]
class CalendarComponent extends AbstractCalendarComponent
{
    private const array GENERAL_EVENT_COLOR = ['bg' => 'bg-sky-100', 'text' => 'text-sky-800', 'border' => 'border-sky-300'];
    private const array PERSONAL_ACTIVITY_COLOR = ['bg' => 'bg-violet-100', 'text' => 'text-violet-800', 'border' => 'border-violet-300'];

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
        private readonly ActivityRepository $activityRepository,
        private readonly ActivityCompletionChecker $activityCompletion,
        private readonly ActivityDeadlineChecker $activityDeadline,
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

        $items = [
            ...$this->getItemsForYear($centre, $academicYear),
            ...$this->getActivityDeadlineItems($centre),
        ];

        return $this->gridBuilder->build(
            $this->year,
            $this->month,
            $items,
            static fn (SchoolEvent|ActivityDeadlineOccurrence $item): array => $item instanceof ActivityDeadlineOccurrence
                ? [
                    'id'    => 'activity-' . $item->activity->getId()->toRfc4122() . ($item->ownerKey !== '' ? '-' . $item->ownerKey : ''),
                    'start' => $item->date,
                    'end'   => $item->date,
                ]
                : [
                    'id'    => 'event-' . $item->getId()->toRfc4122(),
                    'start' => $item->getDate(),
                    'end'   => $item->getDate(),
                ],
            function (SchoolEvent|ActivityDeadlineOccurrence $item): array {
                if ($item instanceof ActivityDeadlineOccurrence) {
                    return [
                        'label'   => $item->activity->getTitle() . ($item->ownerLabel !== null ? ' · ' . $item->ownerLabel : ''),
                        'details' => '',
                        'color'   => $item->ownerKey !== '' ? $this->colorPalette->colorFor($item->ownerKey) : self::PERSONAL_ACTIVITY_COLOR,
                        'icon'    => $item->completed ? 'heroicons:check-circle' : 'heroicons:clipboard-document-check',
                        'muted'   => $item->completed,
                    ];
                }

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

    /** @return list<ActivityDeadlineOccurrence> */
    private function getActivityDeadlineItems(EducationalCentre $centre): array
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            return [];
        }

        $reference = (new \DateTimeImmutable())->setDate($this->year, $this->month, 15);

        $items = [];
        foreach ($this->activityRepository->findAllByCentre($centre) as $activity) {
            foreach ($this->activityCompletion->getMyOwnedObligations($user, $activity) as $owner) {
                $date      = $this->activityDeadline->cycleEndDateNear($activity, $reference);
                $completed = $this->activityCompletion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher']);
                $items[]   = new ActivityDeadlineOccurrence($activity, $date, $owner['label'], $owner['key'], $completed);
            }
        }

        return $items;
    }
}
