<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeTrashMessage;
use App\Service\TrashService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Daily (see Schedule.php): empties from each centre's trash whatever has been there longer than its "trash.retention_days". */
#[AsMessageHandler]
final class PurgeTrashHandler
{
    public function __construct(
        private readonly TrashService $trash,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashMessage $message): void
    {
        $purged = $this->trash->purgeExpired();

        $this->logger->info('Papelera: {documents} documentos, {activities} actividades y {files} ficheros eliminados definitivamente.', $purged);
    }
}
