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

        if ($this->security->isGranted(QualityVoter::MANAGE, $centre)) {
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
            if ($finding === null || !$finding->getStatus()->isOpen() || !$this->isResponsible($teacher, $action)) {
                continue;
            }
            $tasks[] = $this->task(QualityTask::ACTION, $finding, $action, $action->getDueDate());
        }

        usort($tasks, QualityTask::compare(...));

        return $tasks;
    }

    private function task(string $type, Finding $finding, ?ImprovementAction $action, ?\DateTimeImmutable $due): QualityTask
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
    private function isResponsible(Teacher $teacher, ImprovementAction $action): bool
    {
        if ($action->getResponsibleTeacher() !== null) {
            return $action->getResponsibleTeacher()->getId()->equals($teacher->getId());
        }

        $profile = $action->getResponsibleProfile();

        return $profile !== null && $this->access->holdsProfile($teacher, $profile, null);
    }
}
