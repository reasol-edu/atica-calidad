<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeActivityLogMessage;
use App\Repository\ActivityLogRepository;
use App\Service\AppSettingsInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeActivityLogHandler
{
    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly AppSettingsInterface $settings,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(PurgeActivityLogMessage $message): void
    {
        $days = $this->settings->getGlobal('audit.log_retention_days');
        if (!is_int($days) || $days <= 0) {
            return;
        }

        $cutoff = $this->clock->now()->modify("-{$days} days");
        $this->logs->deleteOlderThan($cutoff);
    }
}
