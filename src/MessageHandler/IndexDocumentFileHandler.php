<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\IndexDocumentFileMessage;
use App\Service\DocumentTextIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class IndexDocumentFileHandler
{
    public function __construct(private readonly DocumentTextIndexer $indexer) {}

    public function __invoke(IndexDocumentFileMessage $message): void
    {
        if (Uuid::isValid($message->fileId)) {
            $this->indexer->index(Uuid::fromString($message->fileId));
        }
    }
}
