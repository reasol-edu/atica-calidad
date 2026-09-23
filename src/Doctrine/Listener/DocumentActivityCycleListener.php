<?php

declare(strict_types=1);

namespace App\Doctrine\Listener;

use App\Entity\Document;
use App\Service\ActivityDeadlineChecker;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * A new document in a folder backing an Activity is a submission for the occurrence currently
 * open — the one whoever uploads it is looking at — so it's stamped with that occurrence's cycle
 * key (see ActivityDeadlineChecker::currentCycleKey() and ActivitySubmissionSlotBuilder::resolveSlot()).
 * Done here rather than in DocumentCreationService so no creation path can skip it.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Document::class)]
final class DocumentActivityCycleListener
{
    public function __construct(
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    public function prePersist(Document $document): void
    {
        $activity = $document->getFolder()->getActivity();
        if ($activity === null || $document->getActivityCycleYear() !== null) {
            return;
        }

        $document->setActivityCycleYear($this->deadline->currentCycleKey($activity));
    }
}
