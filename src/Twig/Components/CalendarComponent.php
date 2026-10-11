<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Model\ActivityDeadlineOccurrence;
use App\Model\ProfileAssignmentRow;
use App\Model\QualityTask;
use App\Repository\ActivityRepository;
use App\Repository\SchoolEventRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivityObligationFinder;
use App\Service\AssignmentColorPalette;
use App\Service\CalendarMonthGridBuilder;
use App\Service\NonWorkingDayChecker;
use App\Service\QualityTaskFinder;
use App\Service\TenantContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * Monthly calendar: centre events (by visibility — general for everyone, restricted
 * by assigned profile or subprofile) plus, for each teacher, their own activity deadlines
 * (those where they hold an upload profile — never "all of the centre's", not even as an
 * admin: see ActivityCompletionChecker::getMyOwnedObligations()) and "Mejora continua" deadlines
 * (QualityTaskFinder::dueBetween()) in a monthly grid.
 */
#[AsLiveComponent]
class CalendarComponent extends AbstractCalendarComponent
{
    private const array GENERAL_EVENT_COLOR = ['bg' => 'bg-sky-50', 'text' => 'text-sky-800', 'border' => 'border-sky-200', 'accent' => 'border-l-sky-500'];

    /** "Mejora continua" deadlines: one colour of their own, red once overdue. */
    private const array QUALITY_COLOR = ['bg' => 'bg-violet-50', 'text' => 'text-violet-800', 'border' => 'border-violet-200', 'accent' => 'border-l-violet-500'];

    private const array OVERDUE_QUALITY_COLOR = ['bg' => 'bg-red-50', 'text' => 'text-red-800', 'border' => 'border-red-200', 'accent' => 'border-l-red-500'];

    /** An activity past its deadline and not done: red, like the overdue tasks, whatever its category's colour. */
    private const array OVERDUE_ACTIVITY_COLOR = self::OVERDUE_QUALITY_COLOR;

    /**
     * An activity open for longer than this many days is a "long" window: drawn in full only in the
     * week it falls due, and as a thin track in the weeks before. Filled in full across a month-long
     * window it repeated the same labelled bar on every week row and said nothing about when it is due.
     */
    private const int LONG_WINDOW_DAYS = 7;

    /** @var list<SchoolEvent>|null */
    private ?array $itemsCache = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $weeksCache = null;

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
        private readonly QualityTaskFinder $qualityTasks,
    ) {
        parent::__construct($tenantContext, $translator, $nonWorkingDayChecker, $clock);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeks(): array
    {
        // The template reads this more than once per render (the grid and the phone's agenda).
        return $this->weeksCache ??= $this->buildWeeks();
    }

    /**
     * The days of the month that have something on them, for the phone, where the five-column
     * grid is too narrow to read: the same entries as the grid's bars, listed day by day.
     *
     * @return list<array{date: \DateTimeImmutable, entries: list<array{startCol: int, span: int, label: string, details: string, color: array<string, string>, icon: ?string, muted: bool, long: bool, continues: bool}>}>
     */
    public function getAgendaDays(): array
    {
        $byDay = [];
        foreach ($this->getWeeks() as $week) {
            /** @var list<\DateTimeImmutable> $days */
            $days = $week['days'];
            /** @var list<array{startCol: int, span: int, label: string, details: string, color: array<string, string>, icon: ?string, muted: bool, long: bool, continues: bool}> $segments */
            $segments = $week['segments'];
            foreach ($segments as $segment) {
                $last = $segment['startCol'] + $segment['span'] - 1;
                for ($col = $segment['startCol']; $col <= $last; ++$col) {
                    $day = $days[$col] ?? null;
                    if ($day === null || !$this->isCurrentMonth($day)) {
                        continue;
                    }
                    // A window open for weeks is listed on the day it falls due (or the last school
                    // day before it, when that is a weekend), not on every day of it.
                    if ($segment['long'] && ($segment['continues'] || $col !== $last)) {
                        continue;
                    }
                    $key                      = $day->format('Y-m-d');
                    $byDay[$key]['date']      = $day;
                    $byDay[$key]['entries'][] = $segment;
                }
            }
        }
        ksort($byDay);

        return array_values($byDay);
    }

    /** @return list<array<string, mixed>> */
    private function buildWeeks(): array
    {
        $centre       = $this->getTenantContext()->getSelectedCentre();
        $academicYear = $centre !== null ? $this->getTenantContext()->getViewYear($centre) : null;
        if ($centre === null || $academicYear === null) {
            return [];
        }

        $items = [
            ...$this->getItemsForYear($centre, $academicYear),
            ...$this->getActivityDeadlineItems($centre),
            ...$this->getQualityItems($centre),
        ];

        return $this->gridBuilder->build(
            $this->year,
            $this->month,
            $items,
            static fn (SchoolEvent|ActivityDeadlineOccurrence|QualityTask $item): array => match (true) {
                $item instanceof ActivityDeadlineOccurrence => [
                    'id'    => 'activity-' . $item->activity->getId()->toRfc4122() . ($item->ownerKey !== '' ? '-' . $item->ownerKey : ''),
                    'start' => $item->startDate,
                    'end'   => $item->endDate,
                ],
                $item instanceof QualityTask => [
                    'id'    => 'quality-' . $item->type . '-' . ($item->period !== null
                        ? ($item->indicator?->getId()->toRfc4122() ?? '') . '-' . $item->period->getId()->toRfc4122()
                        : ($item->audit?->getId()->toRfc4122() ?? $item->action?->getId()->toRfc4122() ?? $item->finding?->getId()->toRfc4122() ?? '')),
                    'start' => $item->dueDate ?? new \DateTimeImmutable(),
                    'end'   => $item->dueDate ?? new \DateTimeImmutable(),
                ],
                default => [
                    'id'    => 'event-' . $item->getId()->toRfc4122(),
                    'start' => $item->getDate(),
                    'end'   => $item->getDate(),
                ],
            },
            function (SchoolEvent|ActivityDeadlineOccurrence|QualityTask $item): array {
                if ($item instanceof QualityTask) {
                    return [
                        'label'   => $this->translator->trans('task.' . $item->type, [], 'quality') . ': ' . $item->label(),
                        'details' => $item->code() ?? '',
                        'color'   => $item->urgency === 'overdue' ? self::OVERDUE_QUALITY_COLOR : self::QUALITY_COLOR,
                        'icon'    => match (true) {
                            $item->urgency === 'done'              => 'heroicons:check-circle',
                            $item->type === QualityTask::MEASURE   => 'heroicons:chart-bar',
                            $item->type === QualityTask::AUDIT     => 'heroicons:clipboard-document-check',
                            default                                => 'heroicons:wrench-screwdriver',
                        },
                        'muted'   => $item->urgency === 'done',
                    ];
                }
                if ($item instanceof ActivityDeadlineOccurrence) {
                    return [
                        'label'   => $item->activity->getTitle() . ($item->ownerLabel !== null ? ' · ' . $item->ownerLabel : ''),
                        'details' => '',
                        'long'    => $this->isLongWindow($item),
                        // Colour by category, so every activity of the same category shares a hue
                        // and reads as a group across the month (the owner, if any, is in the label)
                        // — except when it is overdue, which is red: the state matters more.
                        'color'   => $this->isOverdue($item)
                            ? self::OVERDUE_ACTIVITY_COLOR
                            : $this->colorPalette->colorFor('activity-category:' . $item->activity->getCategory()->getId()->toRfc4122()),
                        'icon'    => $item->completed ? 'heroicons:check-circle' : ($this->isOverdue($item) ? 'heroicons:exclamation-circle' : 'heroicons:clipboard-document-check'),
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

    /** A window open for longer than LONG_WINDOW_DAYS (see there). */
    private function isLongWindow(ActivityDeadlineOccurrence $item): bool
    {
        return $item->startDate->diff($item->endDate)->days > self::LONG_WINDOW_DAYS;
    }

    /** Past its deadline and not done. */
    private function isOverdue(ActivityDeadlineOccurrence $item): bool
    {
        return !$item->completed && $item->endDate->format('Y-m-d') < $this->clock->now()->format('Y-m-d');
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
                $leaf = $owner['leaf'];
                $end  = $this->activityDeadline->cycleEndDateNear($activity, $reference, $leaf);
                // A real start–end range fills every day between the two; an obligation whose
                // start and end day/month are the same pair keeps its single-date marker (on that
                // one date) — a leaf with its own override resolves this independently.
                $start = $this->activityDeadline->isSingleDate($activity, $leaf)
                    ? $end
                    : $this->activityDeadline->cycleStartDateNear($activity, $reference, $leaf);

                $completed = $this->activityCompletion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher'], $leaf, $end);
                $items[]   = new ActivityDeadlineOccurrence($activity, $start, $end, $owner['label'], $owner['key'], $completed, ActivityObligationFinder::slotKeyFor($activity, $owner, !$completed));
            }
        }

        return $items;
    }

    /**
     * The teacher's "Mejora continua" deadlines around this month (the grid shows a few days of
     * the neighbouring ones): analyses, actions and verifications due, and their own actions done.
     *
     * @return list<QualityTask>
     */
    private function getQualityItems(EducationalCentre $centre): array
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            return [];
        }
        $first = (new \DateTimeImmutable())->setDate($this->year, $this->month, 1)->setTime(0, 0);

        return $this->qualityTasks->dueBetween($user, $centre, $first->modify('-7 days'), $first->modify('last day of this month')->modify('+7 days'));
    }
}
