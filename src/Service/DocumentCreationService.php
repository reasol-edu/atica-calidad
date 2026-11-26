<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\DocumentFileRepository;
use App\Message\IndexDocumentFileMessage;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Shared "store a file, create a Document with its first revision" logic, extracted from
 * FolderController::upload() so both the document tree's own new-document upload and the
 * activities feature's submission upload (see ActivityController) — which needs a
 * server-computed name instead of one derived from the uploaded file — can create a Document the
 * same way, rather than duplicating the dedup/revision/auto-activate rules in two places.
 */
final class DocumentCreationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentFileRepository $documentFiles,
        private readonly DocumentRepository $documents,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly DocumentVersioning $versioning,
    ) {}

    /**
     * Deduplicates by SHA-256 content hash: reuses an existing DocumentFile if one already matches. A file that is new is also
     * handed to the worker to read its text for the search (a reused one already was).
     */
    public function storeFile(string $content, string $mimeType, string $originalFilename): DocumentFile
    {
        $hash         = hash('sha256', $content);
        $documentFile = $this->documentFiles->findByHash($hash);
        if ($documentFile === null) {
            $documentFile = new DocumentFile($hash, $content, $mimeType, $originalFilename, strlen($content));
            $this->em->persist($documentFile);
            $this->em->flush();
            $this->scheduleIndexing($documentFile);
        }

        return $documentFile;
    }

    /**
     * Never allowed to fail an upload: if the message cannot be queued, the sweep in Schedule reads the file a few minutes later anyway.
     */
    private function scheduleIndexing(DocumentFile $file): void
    {
        try {
            $this->bus->dispatch(new IndexDocumentFileMessage($file->getId()->toRfc4122()));
        } catch (\Throwable $e) {
            $this->logger->warning('Could not queue the text indexing of a new file: {reason}', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * Creates a brand-new Document in $folder with its first revision, activating it immediately
     * unless the folder requires review. Persists but never flushes — callers batch their own
     * flush, so several documents can be created atomically in one request. $version defaults to the
     * lowest number the centre allows (DocumentVersioning: 1, or 0 where the first edition is "v0"),
     * which is the only choice offered anywhere except the document tree's own "upload a new
     * document" dropzone, which lets whoever is creating it pick a starting version.
     */
    public function createWithFirstRevision(
        Folder $folder,
        string $name,
        ?SpecificProfile $profile,
        ?ListItem $listItem,
        UploadedFile $file,
        Teacher $uploader,
        ?int $version = null,
        string $slotCombination = '',
    ): Document {
        $content      = (string) file_get_contents($file->getPathname());
        $documentFile = $this->storeFile($content, $file->getMimeType() ?? 'application/octet-stream', $file->getClientOriginalName());

        return $this->createFromStoredFile($folder, $name, $profile, $listItem, $documentFile, $uploader, $version, false, $slotCombination);
    }

    /**
     * The same as createWithFirstRevision() for a file already stored (the PDF a filled document generates). $slotCombination is the
     * combination of the activity submission it is (ActivitySubmissionSlot::combinationKey()), set before persisting: the cycle it is
     * stamped with follows the dimension value with dates (DocumentActivityCycleListener).
     */
    public function createFromStoredFile(
        Folder $folder,
        string $name,
        ?SpecificProfile $profile,
        ?ListItem $listItem,
        DocumentFile $documentFile,
        Teacher $uploader,
        ?int $version = null,
        bool $forceReview = false,
        string $slotCombination = '',
    ): Document {
        $version ??= $this->versioning->minimum($folder->getEducationalCentre());

        $document = new Document($folder, $name);
        $document->setUploadProfile($profile, $listItem);
        $document->setSlotCombination($slotCombination);
        $document->setPosition($this->documents->nextPositionInFolder($folder));
        $this->em->persist($document);

        // $forceReview: a document with an approval chain of its own is pending whatever the folder asks.
        $pendingReview = $forceReview || $folder->requiresReview();
        $revision      = new DocumentRevision($document, $version, $documentFile, $pendingReview, $uploader);
        $this->em->persist($revision);
        // DocumentRevision's constructor only sets its own $document reference (the owning
        // side); this manually keeps Document::$revisions consistent too, so a caller that
        // inspects the returned Document right away (getPendingRevision(), etc.) — instead of
        // reloading it fresh from the database, where Doctrine would hydrate this collection
        // itself — sees the revision it just created.
        $document->getRevisions()->add($revision);

        if (!$pendingReview) {
            $document->setActiveRevision($revision);
        }

        return $document;
    }

    /**
     * A new revision of an existing document, with the next version number: pending when the folder requires review, active at
     * once otherwise (the same rule as an upload in the tree). Persists but never flushes, and does not notify the reviewers.
     */
    public function addRevision(Document $document, DocumentFile $documentFile, Teacher $uploader, ?string $description = null, bool $forceReview = false): DocumentRevision
    {
        $pendingReview = $forceReview || $document->getFolder()->requiresReview();
        $revision      = new DocumentRevision($document, $document->getNextVersion(), $documentFile, $pendingReview, $uploader);
        $revision->setDescription($description);
        $this->em->persist($revision);
        $document->getRevisions()->add($revision);

        if (!$pendingReview) {
            $document->setActiveRevision($revision);
        }

        return $revision;
    }
}
