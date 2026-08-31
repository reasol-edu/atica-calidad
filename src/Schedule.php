<?php

declare(strict_types=1);

namespace App;

use App\Message\PurgeEmailNotificationLogMessage;
use App\Message\SendDocumentReviewDigestMessage;
use App\Message\SendPendingActivityReminderMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->add(
                RecurringMessage::cron('30 3 * * 0', new PurgeEmailNotificationLogMessage()),
            )
            ->add(
                // Runs every day; the handler itself skips a centre entirely on a weekend or one
                // of its declared non-working days (NonWorkingDayChecker), since holidays are
                // centre/academic-year-specific and can't be expressed in a single cron expression.
                RecurringMessage::cron('0 7 * * *', new SendPendingActivityReminderMessage()),
            )
            ->add(
                // 15 min after the activity reminder — same daily cadence, own cron entry.
                RecurringMessage::cron('15 7 * * *', new SendDocumentReviewDigestMessage()),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
        ;
    }
}
