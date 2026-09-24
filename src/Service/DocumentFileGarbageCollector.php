<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentFile;
use App\Repository\DocumentRevisionRepository;
use App\Repository\QualityAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a DocumentFile if it's no longer referenced by any revision nor any quality attachment
 * (the store is shared and deduplicated by content). Must be called after flushing the change that
 * stopped pointing to the file (replacement or deletion of the revision), so that the count
 * reflects the already-updated state — same pattern as SettingFileGarbageCollector
 * (src/Service/SettingFileGarbageCollector.php).
 */
final class DocumentFileGarbageCollector
{
    public function __construct(
        private readonly DocumentRevisionRepository $revisions,
        private readonly QualityAttachmentRepository $attachments,
        private readonly EntityManagerInterface $em,
    ) {}

    public function deleteIfOrphaned(DocumentFile $file): void
    {
        if ($this->revisions->countByFile($file) > 0 || $this->attachments->countByFile($file) > 0) {
            return;
        }

        $this->em->remove($file);
        $this->em->flush();
    }
}
