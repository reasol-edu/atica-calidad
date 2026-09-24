<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Finding;
use App\Entity\FindingEventKind;
use App\Entity\FindingTimelineEntry;
use App\Entity\Teacher;
use App\Security\Voter\QualityVoter;
use App\Service\ActivityLogger;
use App\Service\AppSettingsInterface;
use App\Service\QualityNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules and the effects of the "finding" state machine (config/packages/workflow.yaml).
 *
 * Guards — who may take each step, and whether the finding is ready for it; each block carries a
 * message the finding page shows next to the step ("Faltan 2 acciones por hacer"). Who: read from
 * the logged-in user. Without one (a console command, e.g. loading the demo data) only the data
 * rules apply.
 *
 * Effects, once a step is taken — context "actor" (Teacher) and "text" (notes, a reason):
 * a FindingTimelineEntry, the activity log entry, dates (verification due date, closing date) and
 * the notifications. Persisted, not flushed: the caller (FindingService) flushes.
 */
final class FindingWorkflowSubscriber implements EventSubscriberInterface
{
    /** Default days between the last action done and the effectiveness check, when the setting can't be read. */
    public const int DEFAULT_VERIFICATION_DAYS = 30;

    private const array TIMELINE = [
        'classify_nonconformity' => FindingEventKind::Classified,
        'classify_other'         => FindingEventKind::Classified,
        'discard'                => FindingEventKind::Discarded,
        'submit_analysis'        => FindingEventKind::AnalysisSubmitted,
        'request_verification'   => FindingEventKind::VerificationRequested,
        'verify_effective'       => FindingEventKind::VerifiedEffective,
        'verify_ineffective'     => FindingEventKind::VerifiedIneffective,
        'close'                  => FindingEventKind::Closed,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly ActivityLogger $activityLogger,
        private readonly QualityNotifier $notifier,
        private readonly AppSettingsInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.finding.guard.classify_nonconformity' => 'guardManager',
            'workflow.finding.guard.classify_other'         => 'guardManager',
            'workflow.finding.guard.discard'                => 'guardManager',
            'workflow.finding.guard.submit_analysis'        => 'guardSubmitAnalysis',
            'workflow.finding.guard.request_verification'   => 'guardRequestVerification',
            'workflow.finding.guard.verify_effective'       => 'guardManager',
            'workflow.finding.guard.verify_ineffective'     => 'guardManager',
            'workflow.finding.guard.close'                  => 'guardClose',
            'workflow.finding.completed'                    => 'onCompleted',
        ];
    }

    /** @param GuardEvent<Finding> $event */
    public function guardManager(GuardEvent $event): void
    {
        $finding = $this->finding($event);
        if ($this->hasUser() && !$this->security->isGranted(QualityVoter::MANAGE, $finding->getEducationalCentre())) {
            $this->block($event, 'only_manager');
        }
    }

    /** @param GuardEvent<Finding> $event */
    public function guardSubmitAnalysis(GuardEvent $event): void
    {
        $finding = $this->finding($event);
        if ($this->hasUser() && !$this->security->isGranted(QualityVoter::FINDING_ANALYZE, $finding)) {
            $this->block($event, 'only_analysis_responsible');

            return;
        }
        if (trim((string) $finding->getRootCause()) === '') {
            $this->block($event, 'root_cause_missing');
        }
        if (!$finding->hasCorrectiveActionForThisAnalysis()) {
            $this->block($event, $finding->isEffective() === false ? 'new_corrective_action_missing' : 'corrective_action_missing');
        }
    }

    /**
     * Only for a nonconformity, and only once every action is done — then taken on its own (FindingService).
     *
     * @param GuardEvent<Finding> $event
     */
    public function guardRequestVerification(GuardEvent $event): void
    {
        $finding = $this->finding($event);
        if (!$finding->isNonconformity()) {
            $this->block($event, 'not_a_nonconformity');

            return;
        }
        $this->blockWhilePendingActions($event, $finding);
    }

    /**
     * Observations and improvement opportunities close directly; a nonconformity is closed by verifying it.
     *
     * @param GuardEvent<Finding> $event
     */
    public function guardClose(GuardEvent $event): void
    {
        $finding = $this->finding($event);
        if ($finding->isNonconformity()) {
            $this->block($event, 'nonconformity_needs_verification');

            return;
        }
        $this->guardManager($event);
        $this->blockWhilePendingActions($event, $finding);
    }

    /** @param CompletedEvent<Finding> $event */
    public function onCompleted(CompletedEvent $event): void
    {
        $finding    = $this->finding($event);
        $transition = $event->getTransition()?->getName() ?? '';
        $context    = $event->getContext();
        $actor      = ($context['actor'] ?? null) instanceof Teacher ? $context['actor'] : null;
        $text       = \is_string($context['text'] ?? null) && trim($context['text']) !== '' ? trim($context['text']) : null;
        $now        = $this->clock->now();

        match ($transition) {
            'request_verification' => $finding->setVerificationDueDate($now->setTime(0, 0)->modify('+' . $this->verificationDays($finding) . ' days')),
            'verify_effective', 'close' => $finding->setClosedAt($now),
            default => null,
        };

        if (isset(self::TIMELINE[$transition])) {
            $this->em->persist(new FindingTimelineEntry($finding, self::TIMELINE[$transition], $actor, $now, $text));
        }

        $this->activityLogger->record('finding.' . $transition, array_filter([
            'finding' => $finding->getCode() ?? $finding->getTitle(),
            'status'  => $finding->getStatus()->value,
        ]), $finding->getEducationalCentre());

        match ($transition) {
            'classify_nonconformity' => $this->notifier->analysisAssigned($finding),
            'discard'                => $this->notifier->discarded($finding),
            'request_verification'   => $this->notifier->verificationRequested($finding),
            'verify_ineffective'     => $this->notifier->verifiedIneffective($finding),
            'verify_effective', 'close' => $this->notifier->closed($finding),
            default                  => null,
        };
    }

    /** @param GuardEvent<Finding> $event */
    private function blockWhilePendingActions(GuardEvent $event, Finding $finding): void
    {
        $pending = $finding->countPendingActions();
        if ($pending > 0) {
            $event->addTransitionBlocker(new TransitionBlocker(
                $this->translator->trans('workflow.blocker.pending_actions', ['%count%' => $pending], 'quality'),
                'pending_actions',
            ));
        }
    }

    /** @param GuardEvent<Finding> $event */
    private function block(GuardEvent $event, string $reason): void
    {
        $event->addTransitionBlocker(new TransitionBlocker($this->translator->trans('workflow.blocker.' . $reason, [], 'quality'), $reason));
    }

    private function verificationDays(Finding $finding): int
    {
        $days = $this->settings->getForCentre('quality.verification_days', $finding->getEducationalCentre());

        return \is_int($days) && $days >= 0 ? $days : self::DEFAULT_VERIFICATION_DAYS;
    }

    private function hasUser(): bool
    {
        return $this->security->getUser() instanceof Teacher;
    }

    /** @param GuardEvent<Finding>|CompletedEvent<Finding> $event */
    private function finding(GuardEvent|CompletedEvent $event): Finding
    {
        return $event->getSubject();
    }
}
