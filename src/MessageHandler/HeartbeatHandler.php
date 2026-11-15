<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\HeartbeatMessage;
use App\Service\SystemState;
use App\Service\SystemStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class HeartbeatHandler
{
    public function __construct(private readonly SystemState $state) {}

    public function __invoke(HeartbeatMessage $message): void
    {
        $this->state->write(SystemStatus::HEARTBEAT, ['at' => time()]);
    }
}
