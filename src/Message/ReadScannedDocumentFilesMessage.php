<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Scheduler tick: do one short turn of OCR on the scanned files that wait for it (DocumentOcrService::run()). Does nothing unless
 * OCR is turned on and installed; costs one cheap query otherwise.
 */
final readonly class ReadScannedDocumentFilesMessage {}
