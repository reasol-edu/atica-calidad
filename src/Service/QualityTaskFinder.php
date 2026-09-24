<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Finding;
use App\Entity\FindingStatus;
use App\Entity\ImprovementAction;
use App\Entity\Teacher;
use App\Model\QualityTask;
use App\Repository\FindingRepository;
use App\Repository\ImprovementActionRepository;
use App\Security\Voter\QualityVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * What a teacher has to do in "Mejora continua" right now, most urgent first:
 *
 * - the quality managers: classify what's been reported, verify what's due, and analyse any
 *   nonconformity that has no analysis responsible;
 * - the analysis responsible: analyse their nonconformities;
 * - anyone: the actions assigned to them, directly or through a profile they hold.
 */
final class QualityTaskFinder
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly ImprovementActionRepository $actions,
        private readonly Security $security,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<QualityTask> */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $tasks = [];

        // For $teacher, not the logged-in user: the daily reminder runs with nobody logged in.
        if ($this->security->isGrantedForUser($teacher, QualityVoter::MANAGE, $centre)) {
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

        usort($tasks, QualityTask::compare(...));

        return $tasks;
    }

    private function task(string $type, ?Finding $finding, ?ImprovementAction $action, ?\DateTimeImmutable $due): QualityTask
    {
        $today   = $this->clock->now()->setTime(0, 0);
        $urgency = match (true) {
            $due === null                                                  => 'open',
            $due < $today                                                  => 'overdue',
            $due <= $today->modify('+' . QualityTask::SOON_DAYS . ' days') => 'soon',
            default                                                        => 'open',
        };

        return new QualityTask($type, $finding, $action, $due, $urgency);
    }

    /** Only the action's own responsible — not the managers as such, who see every action anyway. */
    public function isResponsible(Teacher $teacher, ImprovementAction $action): bool
    {
        if ($action->getResponsibleTeacher() !== null) {
            return $action->getResponsibleTeacher()->getId()->equals($teacher->getId());
        }

        $profile = $action->getResponsibleProfile();

        return $profile !== null && $this->access->holdsProfile($teacher, $profile, null);
    }
}
