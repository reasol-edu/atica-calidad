<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Audit;
use App\Entity\FindingStatus;
use App\Entity\Teacher;
use App\Repository\FindingRepository;
use App\Security\Voter\QualityVoter;
use App\Service\ActivityLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules of the "audit" state machine (config/packages/workflow.yaml) — who may take each step
 * (the audit team or whoever manages "Mejora continua", read from the logged-in user; with none,
 * only the data rules apply) and whether the audit is ready for it, each block with the message
 * the audit page shows — and the activity log entry of each step taken.
 */
final class AuditWorkflowSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly FindingRepository $findings,
        private readonly ActivityLogger $activityLogger,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.audit.guard.prepare'      => 'guardTeam',
            'workflow.audit.guard.start'        => 'guardStart',
            'workflow.audit.guard.issue_report' => 'guardIssueReport',
            'workflow.audit.guard.close'        => 'guardClose',
            'workflow.audit.completed'          => 'onCompleted',
        ];
    }

    /** @param GuardEvent<Audit> $event */
    public function guardTeam(GuardEvent $event): void
    {
        $audit = $this->audit($event);
        if ($this->security->getUser() instanceof Teacher && !$this->security->isGranted(QualityVoter::AUDIT_WORK, $audit)) {
            $this->block($event, 'only_team');
        }
    }

    /** @param GuardEvent<Audit> $event */
    public function guardStart(GuardEvent $event): void
    {
        $this->guardTeam($event);
        $audit = $this->audit($event);
        if ($audit->getScheduledAt() === null) {
            $this->block($event, 'date_missing');
        }
        if ($audit->getLeadAuditor() === null) {
            $this->block($event, 'lead_missing');
        }
        if ($audit->getItems()->isEmpty()) {
            $this->block($event, 'checklist_missing');
        }
    }

    /** @param GuardEvent<Audit> $event */
    public function guardIssueReport(GuardEvent $event): void
    {
        $this->guardTeam($event);
        $audit   = $this->audit($event);
        $pending = $audit->getItems()->count() - $audit->countAnswered();
        if ($pending > 0) {
            $event->addTransitionBlocker(new TransitionBlocker(
                $this->translator->trans('audit.blocker.items_pending', ['%count%' => $pending], 'quality'),
                'items_pending',
            ));
        }
        if (trim((string) $audit->getConclusion()) === '') {
            $this->block($event, 'conclusion_missing');
        }
    }

    /**
     * Closed once every finding its report raised is closed or discarded (AuditService::closeIfDone()).
     *
     * @param GuardEvent<Audit> $event
     */
    public function guardClose(GuardEvent $event): void
    {
        $open = array_filter(
            $this->findings->findByAudit($this->audit($event)),
            static fn ($f): bool => $f->getStatus()->isOpen(),
        );
        if ($open !== []) {
            $event->addTransitionBlocker(new TransitionBlocker(
                $this->translator->trans('audit.blocker.findings_open', ['%count%' => \count($open)], 'quality'),
                'findings_open',
            ));
        }
    }

    /** @param CompletedEvent<Audit> $event */
    public function onCompleted(CompletedEvent $event): void
    {
        $audit = $event->getSubject();
        $this->activityLogger->record('audit.' . ($event->getTransition()?->getName() ?? ''), ['audit' => $audit->getCode() . ' ' . $audit->getTitle()], $audit->getEducationalCentre());
    }

    /** @param GuardEvent<Audit> $event */
    private function audit(GuardEvent $event): Audit
    {
        return $event->getSubject();
    }

    /** @param GuardEvent<Audit> $event */
    private function block(GuardEvent $event, string $reason): void
    {
        $event->addTransitionBlocker(new TransitionBlocker($this->translator->trans('audit.blocker.' . $reason, [], 'quality'), $reason));
    }
}
