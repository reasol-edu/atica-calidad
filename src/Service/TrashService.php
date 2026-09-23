<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\ActivityRepository;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\FolderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The trash ("papelera"): deleting a document (with all its revisions) or an activity (with its
 * completions) moves it here instead, hidden everywhere by TrashFilter. From the trash page it can
 * be restored as it was, or deleted for good; whatever is still there after the centre's
 * "trash.retention_days" (global and centre setting; 0 = never on its own) is purged by the daily
 * PurgeTrashHandler.
 *
 * Deleting what contains them (a folder, an activity category) still deletes them for good,
 * trashed or not, as the database cascades.
 */
final class TrashService
{
    /** When the setting can't be read (e.g. its definition is missing). */
    public const int DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly DocumentRepository $documents,
        private readonly ActivityRepository $activities,
        private readonly FolderRepository $folders,
        private readonly DocumentFileRepository $files,
        private readonly DocumentFileGarbageCollector $garbageCollector,
        private readonly EducationalCentreRepository $centres,
        private readonly AppSettingsInterface $settings,
    ) {}

    /** Days something stays in $centre's trash before it's purged; 0 = until deleted by hand. */
    public function retentionDays(EducationalCentre $centre): int
    {
        $days = $this->settings->getForCentre('trash.retention_days', $centre);

        return \is_int($days) ? max(0, $days) : self::DEFAULT_RETENTION_DAYS;
    }

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

    /**
     * Deletes for good whatever has been in each centre's trash longer than its retention days
     * (centres with 0 are left alone), then any file no revision points to any more.
     *
     * @return array{documents: int, activities: int, files: int}
     */
    public function purgeExpired(): array
    {
        $documents  = 0;
        $activities = 0;
        foreach ($this->centres->findAllOrderedByName() as $centre) {
            $days = $this->retentionDays($centre);
            if ($days === 0) {
                continue;
            }
            $cutoff = $this->clock->now()->modify('-' . $days . ' days');

            foreach ($this->documents->findTrashedBefore($centre, $cutoff) as $document) {
                $this->em->remove($document);
                ++$documents;
            }
            foreach ($this->activities->findTrashedBefore($centre, $cutoff) as $activity) {
                $this->em->remove($activity);
                ++$activities;
            }
        }
        $this->em->flush();

        return ['documents' => $documents, 'activities' => $activities, 'files' => $this->files->deleteOrphans()];
    }
}
