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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
    ) {}

    /** Deduplicates by SHA-256 content hash: reuses an existing DocumentFile if one already matches. */
    public function storeFile(string $content, string $mimeType, string $originalFilename): DocumentFile
    {
        $hash         = hash('sha256', $content);
        $documentFile = $this->documentFiles->findByHash($hash);
        if ($documentFile === null) {
            $documentFile = new DocumentFile($hash, $content, $mimeType, $originalFilename, strlen($content));
            $this->em->persist($documentFile);
            $this->em->flush();
        }

        return $documentFile;
    }

    /**
     * Creates a brand-new Document in $folder with its first revision, activating it immediately
     * unless the folder requires review. Persists but never flushes — callers batch their own
     * flush, so several documents can be created atomically in one request. $version defaults to 1
     * (the only choice offered anywhere except the document tree's own "upload a new document"
     * dropzone, which lets whoever is creating it pick an arbitrary starting version).
     */
    public function createWithFirstRevision(
        Folder $folder,
        string $name,
        ?SpecificProfile $profile,
        ?ListItem $listItem,
        UploadedFile $file,
        Teacher $uploader,
        int $version = 1,
    ): Document {
        $content      = (string) file_get_contents($file->getPathname());
        $documentFile = $this->storeFile($content, $file->getMimeType() ?? 'application/octet-stream', $file->getClientOriginalName());

        $document = new Document($folder, $name);
        $document->setUploadProfile($profile, $listItem);
        $this->em->persist($document);

        $pendingReview = $folder->requiresReview();
        $revision      = new DocumentRevision($document, $version, $documentFile, $pendingReview, $uploader);
        $this->em->persist($revision);

        if (!$pendingReview) {
            $document->setActiveRevision($revision);
        }

        return $document;
    }
}
