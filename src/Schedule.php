<?php

declare(strict_types=1);

namespace App;

use App\Message\PurgeEmailNotificationLogMessage;
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
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
        ;
    }
}
