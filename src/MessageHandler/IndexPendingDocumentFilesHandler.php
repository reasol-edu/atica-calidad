<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\IndexPendingDocumentFilesMessage;
use App\Service\DocumentTextIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexPendingDocumentFilesHandler
{
    public function __construct(private readonly DocumentTextIndexer $indexer) {}

    public function __invoke(IndexPendingDocumentFilesMessage $message): void
    {
        $this->indexer->sweep();
    }
}
