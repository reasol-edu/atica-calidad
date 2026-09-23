<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\Teacher;
use App\Repository\ActivityRepository;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The trash ("papelera"): deleting a document (with all its revisions) or an activity (with its
 * completions) moves it here instead, hidden everywhere by TrashFilter. From the trash page it can
 * be restored as it was, or deleted for good; whatever is still there after RETENTION_DAYS is
 * purged by the daily PurgeTrashHandler.
 *
 * Deleting what contains them (a folder, an activity category) still deletes them for good,
 * trashed or not, as the database cascades.
 */
final class TrashService
{
    public const int RETENTION_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly DocumentRepository $documents,
        private readonly ActivityRepository $activities,
        private readonly FolderRepository $folders,
        private readonly DocumentFileRepository $files,
        private readonly DocumentFileGarbageCollector $garbageCollector,
    ) {}

    public function trashDocument(Document $document, Teacher $by): void
    {
        $document->moveToTrash($this->clock->now(), $by);
        $this->em->flush();
    }

    public function trashActivity(Activity $activity, Teacher $by): void
    {
        $activity->moveToTrash($this->clock->now(), $by);
        $this->em->flush();
    }

    public function restoreDocument(Document $document): void
    {
        $document->restoreFromTrash();
        $this->em->flush();
    }

    /**
     * Back as it was, relinked to its former folder — unless that folder is gone or another
     * activity took it meanwhile, in which case it comes back without one.
     *
     * @return bool whether its folder could be relinked (true as well when it never had one)
     */
    public function restoreActivity(Activity $activity): bool
    {
        $folderId = $activity->getTrashedFolderId();
        $folder   = $folderId === null ? null : $this->folders->findById($folderId->toRfc4122());
        if ($folder !== null && $folder->getActivity() !== null) {
            $folder = null;
        }

        $activity->restoreFromTrash($folder);
        $this->em->flush();

        return $folderId === null || $folder !== null;
    }

    public function purgeDocument(Document $document): void
    {
        $files = array_map(static fn ($revision): DocumentFile => $revision->getFile(), $document->getRevisions()->toArray());

        $this->em->remove($document);
        $this->em->flush();

        foreach ($files as $file) {
            $this->garbageCollector->deleteIfOrphaned($file);
        }
    }

    public function purgeActivity(Activity $activity): void
    {
        $this->em->remove($activity);
        $this->em->flush();
    }

    /** When something deleted on $deletedAt leaves the trash for good. */
    public function expiresOn(\DateTimeImmutable $deletedAt): \DateTimeImmutable
    {
        return $deletedAt->modify('+' . self::RETENTION_DAYS . ' days');
    }

    /**
     * Deletes for good whatever has been in the trash longer than RETENTION_DAYS, then any file no
     * revision points to any more.
     *
     * @return array{documents: int, activities: int, files: int}
     */
    public function purgeExpired(): array
    {
        $cutoff = $this->clock->now()->modify('-' . self::RETENTION_DAYS . ' days');

        $documents = $this->documents->findTrashedBefore($cutoff);
        foreach ($documents as $document) {
            $this->em->remove($document);
        }
        $activities = $this->activities->findTrashedBefore($cutoff);
        foreach ($activities as $activity) {
            $this->em->remove($activity);
        }
        $this->em->flush();

        return ['documents' => \count($documents), 'activities' => \count($activities), 'files' => $this->files->deleteOrphans()];
    }
}
