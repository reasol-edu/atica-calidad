<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ReadScannedDocumentFilesMessage;
use App\Service\DocumentOcrService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReadScannedDocumentFilesHandler
{
    public function __construct(private readonly DocumentOcrService $ocr) {}

    public function __invoke(ReadScannedDocumentFilesMessage $message): void
    {
        $this->ocr->run();
    }
}
