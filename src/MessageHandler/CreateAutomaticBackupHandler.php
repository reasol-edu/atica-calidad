<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CreateAutomaticBackupMessage;
use App\Service\Backup\AutomaticBackup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Nightly (see Schedule.php): saves an automatic backup and prunes by count and size (global settings `backup.auto_policy` / `backup.auto_max_size_mb`; "off" / 0 = off). Never throws, so Messenger never retries it. */
#[AsMessageHandler]
final class CreateAutomaticBackupHandler
{
    public function __construct(private readonly AutomaticBackup $automaticBackup) {}

    public function __invoke(CreateAutomaticBackupMessage $message): void
    {
        $this->automaticBackup->run();
    }
}
