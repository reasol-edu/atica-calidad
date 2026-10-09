<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityStatusReportRow;
use App\Repository\ActivityReminderRepository;
use App\Repository\ActivityRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityPendingOwnersFinder;
use App\Service\ActivityStatusReportBuilder;
use App\Service\DocumentTreeAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Seguimiento" of the centre's activities: one panel with how each stands in its current
 * occurrence (progress, who's missing, days left) and, per activity, who exactly still has
 * something to do with the reminders already sent and a form to send another — optionally to just
 * some of them and with a message of your own. Sending itself is ActivityController::remindPending().
 */
#[Route('/actividades/seguimiento')]
class ActivityTrackingController extends AbstractController
{
    public function __construct(
        private readonly ActivityStatusReportBuilder $report,
        private readonly ActivityRepository $activities,
        private readonly ActivityPendingOwnersFinder $pendingOwners,
        private readonly ActivityReminderRepository $reminders,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ClockInterface $clock,
    ) {}

    /** Every visible activity, the ones with something pending and the soonest deadline first. */
    #[Route('', name: 'app_activity_tracking')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        if (!$this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre) && !$this->isGranted(EducationalCentreVoter::REPORTS, $centre)) {
            throw $this->createAccessDeniedException();
        }

        $today       = $this->clock->now();
        $onlyPending = $request->query->getBoolean('pendientes');
        $rows        = $this->report->build($centre);
        if ($onlyPending) {
            $rows = array_values(array_filter($rows, static fn (ActivityStatusReportRow $r): bool => $r->pending() > 0));
        }
        usort($rows, static fn (ActivityStatusReportRow $a, ActivityStatusReportRow $b): int => [$a->pending() > 0 ? 0 : 1, $a->daysLeft($today) < 0 && $a->pending() > 0 ? 0 : 1, $a->deadline]
            <=> [$b->pending() > 0 ? 0 : 1, $b->daysLeft($today) < 0 && $b->pending() > 0 ? 0 : 1, $b->deadline]);

        return $this->render('activity/tracking.html.twig', [
            'centre'      => $centre,
            'rows'        => $rows,
            'today'       => $today,
            'onlyPending' => $onlyPending,
            'canEdit'     => $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre),
        ]);
    }

    /** Who still has something to do for one activity, when each was last reminded, and the reminders sent. */
    #[Route('/{activityId}', name: 'app_activity_tracking_detail', requirements: ['activityId' => '[0-9a-fA-F-]{36}'])]
    public function detail(string $activityId, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $activity = $this->requireActivity($activityId, $centre);
        $canSend  = $this->canRemind($activity, $centre);
        if (!$canSend && !$this->isGranted(EducationalCentreVoter::REPORTS, $centre)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('activity/tracking_detail.html.twig', [
            'centre'   => $centre,
            'activity' => $activity,
            'pending'  => $this->pendingOwners->find($activity),
            'last'     => $this->reminders->lastSentByTeacher($activity),
            'history'  => $this->reminders->findByActivity($activity),
            'canSend'  => $canSend,
        ]);
    }

    private function requireActivity(string $activityId, EducationalCentre $centre): Activity
    {
        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getCategory()->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        if ($activity->isHidden() && !$this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre)) {
            throw $this->createNotFoundException();
        }

        return $activity;
    }

    /** Same rule as ActivityController::canRemind() and the button on the activity card. */
    private function canRemind(Activity $activity, EducationalCentre $centre): bool
    {
        $user   = $this->getUser();
        $folder = $activity->getFolder();

        return $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre)
            || ($user instanceof Teacher && $folder !== null && ($this->access->canManageFolder($user, $folder) || $this->access->canReviewFolder($user, $folder)));
    }
}
