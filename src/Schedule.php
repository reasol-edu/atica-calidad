<?php

declare(strict_types=1);

namespace App;

use App\Message\AutoPrescribeIncidentReportsMessage;
use App\Message\DeactivateExpiredDailyNotesMessage;
use App\Message\PurgeActivityAttachmentsMessage;
use App\Message\PurgeActivityLogMessage;
use App\Message\PurgeEmailNotificationLogMessage;
use App\Message\PurgeSanctionTaskAttachmentsMessage;
use App\Message\SanctionTaskReminderMessage;
use App\Message\WarnUpcomingReportPrescriptionsMessage;
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
                RecurringMessage::cron('0 1 * * *', new DeactivateExpiredDailyNotesMessage()),
                RecurringMessage::cron('0 3 * * 0', new PurgeActivityLogMessage()),
                RecurringMessage::cron('30 3 * * 0', new PurgeEmailNotificationLogMessage()),
                RecurringMessage::cron('0 4 * * *', new AutoPrescribeIncidentReportsMessage()),
                RecurringMessage::cron('30 4 * * *', new WarnUpcomingReportPrescriptionsMessage()),
                RecurringMessage::cron('0 5 * * 0', new PurgeActivityAttachmentsMessage()),
                RecurringMessage::cron('30 5 * * 0', new PurgeSanctionTaskAttachmentsMessage()),
                RecurringMessage::cron('0 6 * * *', new SanctionTaskReminderMessage()),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
        ;
    }
}
