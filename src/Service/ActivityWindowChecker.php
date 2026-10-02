<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ListItem;
use App\Entity\Teacher;
use App\Model\ActivityWindow;
use App\Model\ActivityWindowBlock;
use Symfony\Component\Clock\ClockInterface;

/**
 * Decides whether an activity's enforced start/end dates currently let $teacher submit or mark it
 * as completed. Composes the existing date logic (ActivityDeadlineChecker) and the existing
 * permission logic (DocumentTreeAccessChecker) — no dates are recomputed here.
 *
 * Bypass: quality coordinators, the folder's responsibles and admins may always act (and fix
 * things out of period). For a folderless activity there is no "folder responsible", so bypass is
 * just admin / quality manager.
 */
final class ActivityWindowChecker
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    /** $leaf, when given, resolves to its own deadline override (see Activity::getDeadlineOverride()) instead of the activity's own. */
    public function for(Activity $activity, Teacher $teacher, ?ListItem $leaf = null): ActivityWindow
    {
        $now   = $this->clock->now();
        $start = $this->deadline->currentCycleStartDate($activity, $leaf);
        $end   = $this->deadline->currentCycleEndDate($activity, $leaf);

        $graceUntil = $activity->isEndDateEnforced() && $activity->getEndDateGraceDays() > 0
            ? $end->modify('+' . $activity->getEndDateGraceDays() . ' days')
            : $end;

        $bypass      = $this->canBypass($activity, $teacher);
        $beforeStart = $activity->isStartDateEnforced() && $now < $start;
        $afterEnd    = $activity->isEndDateEnforced() && $now > $end;
        $afterGrace  = $activity->isEndDateEnforced() && $now > $graceUntil;

        $reason = match (true) {
            $bypass      => null,
            $beforeStart => ActivityWindowBlock::BeforeStart,
            $afterGrace  => ActivityWindowBlock::AfterEnd,
            default      => null,
        };

        return new ActivityWindow(
            blocked:    $reason !== null,
            reason:     $reason,
            late:       $afterEnd && $reason === null,
            bypassing:  $bypass && ($beforeStart || $afterGrace),
            notStarted: $now < $start,
            startDate:  $start,
            endDate:    $end,
            graceUntil: $graceUntil,
        );
    }

    public function canBypass(Activity $activity, Teacher $teacher): bool
    {
        $folder = $activity->getFolder();

        return $folder !== null
            ? $this->access->canManageFolder($teacher, $folder)
            : $this->access->isAdminOrQualityManager($teacher, $activity->getCategory()->getEducationalCentre());
    }
}
