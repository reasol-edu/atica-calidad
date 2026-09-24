<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Finding;
use App\Service\AuditService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * An internal audit closes on its own once every finding its report raised is closed or
 * discarded: each time one of them is, see whether it was the last. (Its guard counts the findings
 * as loaded — the one just closed already reads closed, though not flushed yet.)
 */
final class AuditClosureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditService $audits,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.finding.completed.verify_effective' => 'onFindingClosed',
            'workflow.finding.completed.close'            => 'onFindingClosed',
            'workflow.finding.completed.discard'          => 'onFindingClosed',
        ];
    }

    /** @param CompletedEvent<Finding> $event */
    public function onFindingClosed(CompletedEvent $event): void
    {
        $audit = $event->getSubject()->getAuditItem()?->getAudit();
        if ($audit !== null) {
            $this->audits->closeIfDone($audit);
        }
    }
}
