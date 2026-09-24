<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Audit;
use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\Indicator;
use App\Entity\IndicatorStatus;
use App\Entity\MeasurementPeriod;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\IndicatorCell;
use App\Model\IndicatorRow;
use App\Model\QualityTask;
use App\Repository\AuditProgramRepository;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Security\Voter\QualityVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * What a teacher has to do in "Mejora continua" right now, most urgent first:
 *
 * - the quality managers: classify what's been reported, verify what's due, analyse any
 *   nonconformity that has no analysis responsible, and decide on indicator values off target;
 * - the analysis responsible: analyse their nonconformities;
 * - anyone: the actions assigned to them, and the values to record of the indicators they're
 *   responsible for — directly or through a profile they hold — once each period is over;
 * - an audit team: its audits still to carry out, from AUDIT_NOTICE_DAYS before they're due;
 * - the management team: approving the year's audit programme, while it isn't.
 */
final class QualityTaskFinder
{
    /** How long before an audit is due its team has it as a task. */
    public const int AUDIT_NOTICE_DAYS = 30;

    public function __construct(
        private readonly FindingRepository $findings,
        private readonly ImprovementActionRepository $actions,
        private readonly AuditProgramRepository $programs,
        private readonly IndicatorBoardBuilder $indicators,
        private readonly Security $security,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<QualityTask> */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $tasks = [];

        // For $teacher, not the logged-in user: the daily reminder runs with nobody logged in.
        $manages = $this->security->isGrantedForUser($teacher, QualityVoter::MANAGE, $centre);
        if ($manages) {
            foreach ($this->findings->findByCentreAndStatus($centre, FindingStatus::Reported) as $finding) {
                $tasks[] = new QualityTask(QualityTask::CLASSIFY, $finding, null, null, 'open');
            }
            foreach ($this->findings->findByCentreAndStatus($centre, FindingStatus::Verification) as $finding) {
                $tasks[] = $this->task(QualityTask::VERIFY, $finding, null, $finding->getVerificationDueDate());
            }
            foreach ($this->findings->findByCentreAndStatus($centre, FindingStatus::Analysis) as $finding) {
                if ($finding->getAnalysisResponsible() === null) {
                    $tasks[] = $this->task(QualityTask::ANALYZE, $finding, null, $finding->getAnalysisDueDate());
                }
            }
        }

        foreach ($this->findings->findAwaitingAnalysisBy($teacher, $centre) as $finding) {
            $tasks[] = $this->task(QualityTask::ANALYZE, $finding, null, $finding->getAnalysisDueDate());
        }

        foreach ($this->actions->findOpenByCentre($centre) as $action) {
            $finding = $action->getFinding();
            if (($finding !== null && !$finding->getStatus()->isOpen()) || !$this->isResponsible($teacher, $action)) {
                continue;
            }
            $tasks[] = $this->task(QualityTask::ACTION, $finding, $action, $action->getDueDate());
        }

        foreach ($this->indicatorRows($centre) as $row) {
            $responsible = $this->isResponsibleFor($teacher, $row->indicator->getResponsibleTeacher(), $row->indicator->getResponsibleProfile());
            foreach ($row->cells as $cell) {
                if ($responsible && \in_array($cell->state, [IndicatorCell::PENDING, IndicatorCell::LATE], true)) {
                    $tasks[] = $this->task(QualityTask::MEASURE, null, null, $cell->dueDate, $row->indicator, $cell->period);
                } elseif ($manages && $cell->measurement !== null && !$cell->measurement->isReviewed() && $cell->status() === IndicatorStatus::OffTarget) {
                    $tasks[] = new QualityTask(QualityTask::REVIEW, null, null, null, 'open', $row->indicator, $cell->period, $cell->measurement);
                }
            }
        }

        $program = $centre->getActiveAcademicYear() === null ? null : $this->programs->findByYear($centre->getActiveAcademicYear());
        if ($program !== null) {
            $first = $program->getAudits()->first();
            if ($first !== false && !$program->isApproved() && $this->security->isGrantedForUser($teacher, QualityVoter::AUDIT_APPROVE, $centre)) {
                $tasks[] = new QualityTask(QualityTask::APPROVE, null, null, null, 'open', audit: $first);
            }
            // The team's audits still to carry out, from a few weeks before they're due.
            $horizon = $this->clock->now()->setTime(0, 0)->modify('+' . self::AUDIT_NOTICE_DAYS . ' days');
            foreach ($program->getAudits() as $audit) {
                if ($audit->getStatus()->isPending() && $audit->isInTeam($teacher) && $audit->getDueDate() <= $horizon) {
                    $tasks[] = $this->task(QualityTask::AUDIT, null, null, $audit->getDueDate(), audit: $audit);
                }
            }
        }

        usort($tasks, QualityTask::compare(...));

        return $tasks;
    }

    /**
     * For the calendar: the teacher's tasks due between $from and $to (inclusive), plus the actions
     * of theirs due then that are already done (urgency "done"), as the calendar also shows the
     * activities already completed.
     *
     * @return list<QualityTask>
     */
    public function dueBetween(Teacher $teacher, EducationalCentre $centre, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0);
        $to   = $to->setTime(0, 0);

        $tasks = array_values(array_filter(
            $this->forTeacher($teacher, $centre),
            static fn (QualityTask $t): bool => $t->dueDate !== null && $t->dueDate >= $from && $t->dueDate <= $to,
        ));
        foreach ($this->actions->findDueBetween($centre, $from, $to) as $action) {
            if ($action->isDone() && $this->isResponsible($teacher, $action)) {
                $tasks[] = new QualityTask(QualityTask::ACTION, $action->getFinding(), $action, $action->getDueDate(), 'done');
            }
        }
        // Audits on those days: the team's (not already a task: scheduled far ahead, or done) and
        // where the teacher is audited.
        $program = $centre->getActiveAcademicYear() === null ? null : $this->programs->findByYear($centre->getActiveAcademicYear());
        foreach ($program?->getAudits() ?? [] as $audit) {
            $day = $audit->getScheduledAt()?->setTime(0, 0);
            if ($day === null || $day < $from || $day > $to) {
                continue;
            }
            $inTeam = $audit->isInTeam($teacher);
            if (($inTeam && !$this->hasTaskFor($tasks, $audit)) || (!$inTeam && $this->isAudited($teacher, $audit))) {
                $tasks[] = new QualityTask(QualityTask::AUDIT, null, null, $day, $audit->getStatus()->isPending() ? 'open' : 'done', audit: $audit);
            }
        }
        // Values to record later on (not asked for yet), and those already recorded.
        foreach ($this->indicatorRows($centre) as $row) {
            if (!$this->isResponsibleFor($teacher, $row->indicator->getResponsibleTeacher(), $row->indicator->getResponsibleProfile())) {
                continue;
            }
            foreach ($row->cells as $cell) {
                if ($cell->dueDate < $from || $cell->dueDate > $to) {
                    continue;
                }
                if ($cell->state === IndicatorCell::FUTURE) {
                    $tasks[] = new QualityTask(QualityTask::MEASURE, null, null, $cell->dueDate, 'open', $row->indicator, $cell->period);
                } elseif ($cell->state === IndicatorCell::VALUE) {
                    $tasks[] = new QualityTask(QualityTask::MEASURE, null, null, $cell->dueDate, 'done', $row->indicator, $cell->period, $cell->measurement);
                }
            }
        }

        usort($tasks, QualityTask::compare(...));

        return $tasks;
    }

    /** @return list<IndicatorRow> the active year's indicators (those with a target for it) */
    private function indicatorRows(EducationalCentre $centre): array
    {
        $year = $centre->getActiveAcademicYear();

        return $year === null ? [] : array_merge([], ...array_values($this->indicators->board($centre, $year)));
    }

    /** @param list<QualityTask> $tasks */
    private function hasTaskFor(array $tasks, Audit $audit): bool
    {
        foreach ($tasks as $task) {
            if ($task->audit === $audit) {
                return true;
            }
        }

        return false;
    }

    private function isAudited(Teacher $teacher, Audit $audit): bool
    {
        foreach ($audit->getScope() as $section) {
            foreach (AuditService::foldersUnder($section) as $folder) {
                if ($this->access->holdsResponsibleProfile($teacher, $folder)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function task(string $type, ?Finding $finding, ?ImprovementAction $action, ?\DateTimeImmutable $due, ?Indicator $indicator = null, ?MeasurementPeriod $period = null, ?Audit $audit = null): QualityTask
    {
        $today   = $this->clock->now()->setTime(0, 0);
        $urgency = match (true) {
            $due === null                                                  => 'open',
            $due < $today                                                  => 'overdue',
            $due <= $today->modify('+' . QualityTask::SOON_DAYS . ' days') => 'soon',
            default                                                        => 'open',
        };

        return new QualityTask($type, $finding, $action, $due, $urgency, $indicator, $period, audit: $audit);
    }

    /** Only the action's own responsible — not the managers as such, who see every action anyway. */
    public function isResponsible(Teacher $teacher, ImprovementAction $action): bool
    {
        return $this->isResponsibleFor($teacher, $action->getResponsibleTeacher(), $action->getResponsibleProfile());
    }

    private function isResponsibleFor(Teacher $teacher, ?Teacher $responsibleTeacher, ?SpecificProfile $responsibleProfile): bool
    {
        if ($responsibleTeacher !== null) {
            return $responsibleTeacher->getId()->equals($teacher->getId());
        }

        return $responsibleProfile !== null && $this->access->holdsProfile($teacher, $responsibleProfile, null);
    }
}
