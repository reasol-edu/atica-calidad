<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivitySubmissionScope;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Model\ActivityYearReviewRow;
use App\Repository\ActivityRepository;
use App\Repository\SpecificProfileAssignmentRepository;

/**
 * The start-of-year review of a centre's activities ("Preparar el nuevo curso"): for each one, what
 * it asks and of whom, and whether those people exist in the year being prepared — a profile whose
 * holders all left, or an activity that would ask nobody, is what to fix before teachers start.
 *
 * Activities are not copied from year to year: their day/month deadlines and their folder carry
 * over by themselves (see ActivityDeadlineChecker's cycles), so there is nothing to "prepare" in
 * them except who is asked.
 */
class ActivityYearReviewBuilder
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly ActivitySubmissionSlotBuilder $slots,
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    /**
     * Every activity of the centre, hidden ones included (they'll be shown eventually), in category order.
     *
     * @return list<ActivityYearReviewRow>
     */
    public function build(EducationalCentre $centre, AcademicYear $year): array
    {
        $members = [];
        foreach ($year->getTeachers() as $teacher) {
            $members[$teacher->getId()->toRfc4122()] = true;
        }
        // profile id => list item id (or '') => how many of the year's teachers hold it
        $holders = [];
        foreach ($this->assignments->findAllForCentre($centre) as $assignment) {
            if (!isset($members[$assignment->getTeacher()->getId()->toRfc4122()])) {
                continue;
            }
            $profileId = $assignment->getSpecificProfile()->getId()->toRfc4122();
            $itemId    = $assignment->getListItem()?->getId()->toRfc4122() ?? '';
            $holders[$profileId][$itemId] = ($holders[$profileId][$itemId] ?? 0) + 1;
        }

        $rows = [];
        foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
            $rows[] = $this->row($activity, $holders, \count($members));
        }

        return $rows;
    }

    /** How many of the rows have something to fix. */
    public function countWithIssues(EducationalCentre $centre, AcademicYear $year): int
    {
        return \count(array_filter($this->build($centre, $year), static fn (ActivityYearReviewRow $r): bool => $r->hasIssues()));
    }

    /** @param array<string, array<string, int>> $holders */
    private function row(Activity $activity, array $holders, int $teacherCount): ActivityYearReviewRow
    {
        $folder      = $activity->getFolder();
        $submissions = 0;
        $pairs       = [];
        $everyone    = false;

        if ($folder !== null) {
            // By profile, whatever the scope: what matters here is which profiles are asked, not who holds them today.
            $slots       = $this->slots->buildSlotsFor(
                $activity->getListItem(),
                $activity->getTags()->toArray(),
                ActivitySubmissionScope::ByProfile,
                $this->access->getFolderUploadRows($folder),
            );
            $submissions = \count($slots);
            foreach ($slots as $slot) {
                $pairs[$slot->profile->getId()->toRfc4122() . '|' . ($slot->listItem?->getId()->toRfc4122() ?? '')] = [$slot->profile, $slot->listItem];
            }
            $responsible = $folder->getResponsibleProfiles()->toArray();
        } else {
            $everyone = $activity->isGeneral();
            foreach ($activity->getProfileRestrictions() as $restriction) {
                $pairs[$restriction->getSpecificProfile()->getId()->toRfc4122() . '|' . ($restriction->getListItem()?->getId()->toRfc4122() ?? '')] = [$restriction->getSpecificProfile(), $restriction->getListItem()];
            }
            $responsible = $activity->getResponsibleProfiles()->toArray();
        }

        $asked = array_map(fn (array $pair): array => $this->describe($pair[0], $pair[1], $holders), array_values($pairs));
        $managers = [];
        foreach ($responsible as $r) {
            $managers[] = $this->describe($r->getSpecificProfile(), $r->getListItem(), $holders);
        }
        usort($asked, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        usort($managers, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $issues = [];
        if ($folder !== null && $submissions === 0) {
            $issues[] = ActivityYearReviewRow::ISSUE_NOBODY_ASKED;
        } elseif (!$everyone && $asked === []) {
            // A restricted manual activity with no profile left: nobody is asked either.
            $issues[] = ActivityYearReviewRow::ISSUE_NOBODY_ASKED;
        } elseif ($asked !== []) {
            $unstaffed = \count(array_filter($asked, static fn (array $p): bool => $p['teachers'] === 0));
            if ($unstaffed === \count($asked)) {
                $issues[] = ActivityYearReviewRow::ISSUE_NOBODY_IN_YEAR;
            } elseif ($unstaffed > 0) {
                $issues[] = ActivityYearReviewRow::ISSUE_SOME_UNSTAFFED;
            }
        }
        if ($everyone && $teacherCount === 0) {
            $issues[] = ActivityYearReviewRow::ISSUE_NOBODY_IN_YEAR;
        }
        if ($managers !== [] && \count(array_filter($managers, static fn (array $p): bool => $p['teachers'] > 0)) === 0) {
            $issues[] = ActivityYearReviewRow::ISSUE_RESPONSIBLE_UNSTAFFED;
        }

        return new ActivityYearReviewRow(
            $activity,
            $activity->getCategory()->getName(),
            $folder !== null,
            $everyone,
            $submissions,
            $asked,
            $managers,
            $issues,
        );
    }

    /**
     * @param array<string, array<string, int>> $holders
     *
     * @return array{name: string, teachers: int}
     */
    private function describe(SpecificProfile $profile, ?ListItem $listItem, array $holders): array
    {
        $byItem = $holders[$profile->getId()->toRfc4122()] ?? [];
        // A whole-profile row ("(todos)") is held by whoever holds any of its subprofiles.
        $teachers = $listItem === null ? array_sum($byItem) : ($byItem[$listItem->getId()->toRfc4122()] ?? 0);

        return ['name' => $profile->getName() . ($listItem !== null ? ' ' . $listItem->getName() : ''), 'teachers' => $teachers];
    }
}
