<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FolderRepository;
use App\Security\Voter\FolderVoter;
use App\Service\AttachmentDownloadResponder;
use App\Service\DocumentCreationService;
use App\Service\DocumentTreeAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Uploading, downloading and reviewing a folder's documents — always a plain multipart POST
 * controller (see ListItemController::import()/DocumentTreeController::import() for the same
 * convention), never a LiveAction: Symfony UX Live Components in this app never handle file
 * uploads directly. Renaming, deleting, moving, reordering documents and picking a document's
 * active revision are plain state changes and live as LiveActions on SectionBrowserComponent
 * instead.
 */
#[Route('/arbol-documental/carpetas/{folderId}')]
class FolderController extends AbstractController
{
    use TranslatorTrait;
    use UploadSizeGuardTrait;

    private const MAX_DOCUMENT_SIZE = 20 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly FolderRepository $folders,
        private readonly DocumentRepository $documents,
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentTreeAccessChecker $access,
        private readonly AttachmentDownloadResponder $downloadResponder,
        private readonly DocumentCreationService $documentCreation,
    ) {}

    /**
     * Uploads one or more new documents in a single step: the dropzone already collects, per file,
     * the document name/initial version/upload profile client-side (see
     * assets/controllers/file_drop_controller.js), submitted alongside the files themselves — no
     * separate confirmation screen or server-side upload batch.
     */
    #[Route('/subir', name: 'app_folder_upload', methods: ['POST'])]
    public function upload(string $folderId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $folder  = $this->requireFolder($folderId, $centre);
        $teacher = $this->requireTeacher();
        $this->denyAccessUnlessGranted(FolderVoter::UPLOAD, $folder);

        if ($this->isUploadTooLarge($request)) {
            $this->addFlash('error', $this->t('upload.error.too_large'));

            return $this->redirectToFolder($folder, $request);
        }

        if (!$this->isCsrfTokenValid('folder_upload_' . $folderId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $uploadedFiles = $request->files->all('files');
        if ($uploadedFiles === []) {
            $this->addFlash('error', $this->t('upload.error.no_file'));

            return $this->redirectToFolder($folder, $request);
        }

        $allowedProfileRows = $this->access->allowedUploadProfileRows($teacher, $folder);
        $requiresProfile    = $folder->isGroupByProfile();
        /** @var array<int, array{version?: string, profileKey?: string}> $items */
        $items = $request->request->all('items');

        $created = 0;
        foreach ($uploadedFiles as $i => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            if ($file->getSize() > self::MAX_DOCUMENT_SIZE) {
                $this->addFlash('error', $this->translator->trans('upload.error.file_too_large', [
                    '%filename%' => $file->getClientOriginalName(),
                ], 'document_content'));

                return $this->redirectToFolder($folder, $request);
            }

            $item    = $items[$i] ?? [];
            $name    = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $version = max(1, (int) ($item['version'] ?? 1));

            $profileKey = (string) ($item['profileKey'] ?? '');
            $profile    = null;
            $listItem   = null;
            if ($requiresProfile && $profileKey !== '') {
                foreach ($allowedProfileRows as $row) {
                    if ($row->key() === $profileKey) {
                        $profile  = $row->profile;
                        $listItem = $row->listItem;

                        break;
                    }
                }
            }

            $this->documentCreation->createWithFirstRevision($folder, $name, $profile, $listItem, $file, $teacher, $version);

            ++$created;
        }

        if ($created === 0) {
            $this->addFlash('error', $this->t('upload.error.no_file'));

            return $this->redirectToFolder($folder, $request);
        }

        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('upload.flash.created', ['%count%' => $created], 'document_content'));

        return $this->redirectToFolder($folder, $request);
    }

    #[Route('/documentos/{documentId}/revisiones', name: 'app_folder_document_new_revision', methods: ['POST'])]
    public function uploadRevision(string $folderId, string $documentId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $folder   = $this->requireFolder($folderId, $centre);
        $document = $this->requireDocument($documentId, $folder);
        $teacher  = $this->requireTeacher();
        if (!$this->access->canManageDocumentAsUploader($teacher, $document)) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isUploadTooLarge($request)) {
            $this->addFlash('error', $this->t('upload.error.too_large'));

            return $this->redirectToFolder($folder, $request);
        }

        if (!$this->isCsrfTokenValid('folder_document_revision_' . $documentId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('upload.error.no_file'));

            return $this->redirectToFolder($folder, $request);
        }
        if ($file->getSize() > self::MAX_DOCUMENT_SIZE) {
            $this->addFlash('error', $this->t('upload.error.too_large'));

            return $this->redirectToFolder($folder, $request);
        }

        $canManage = $this->access->canManageFolder($teacher, $folder);
        $version   = $document->getNextVersion();
        if ($canManage && $request->request->has('version')) {
            $requestedVersion = $request->request->getInt('version');
            if ($requestedVersion < 1) {
                $this->addFlash('error', $this->t('revision.error.invalid_version'));

                return $this->redirectToFolder($folder, $request);
            }
            if ($document->hasVersion($requestedVersion)) {
                $this->addFlash('error', $this->translator->trans('revision.error.version_in_use', [
                    '%version%' => $requestedVersion,
                ], 'document_content'));

                return $this->redirectToFolder($folder, $request);
            }
            $version = $requestedVersion;
        }

        $content      = (string) file_get_contents($file->getPathname());
        $documentFile = $this->documentCreation->storeFile($content, $file->getMimeType() ?? 'application/octet-stream', $file->getClientOriginalName());

        $pendingReview = $folder->requiresReview();
        $revision      = new DocumentRevision($document, $version, $documentFile, $pendingReview, $teacher);
        $this->em->persist($revision);

        if (!$pendingReview) {
            $document->setActiveRevision($revision);
        }

        $this->em->flush();

        $this->addFlash('success', $this->t('revision.flash.uploaded'));

        return $this->redirectToFolder($folder, $request);
    }

    #[Route('/documentos/{documentId}/revisiones/{revisionId}/descargar', name: 'app_folder_document_revision_download', methods: ['GET'])]
    public function download(string $folderId, string $documentId, string $revisionId, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $folder   = $this->requireFolder($folderId, $centre);
        $document = $this->requireDocument($documentId, $folder);
        $this->denyAccessUnlessGranted(FolderVoter::VIEW, $folder);

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $file = $revision->getFile();

        return $this->downloadResponder->respond($file->getContent(), $file->getMimeType(), $this->downloadFilename($document, $file));
    }

    /** The document's own name, keeping the extension of the file that was actually uploaded. */
    private function downloadFilename(Document $document, DocumentFile $file): string
    {
        $extension = pathinfo($file->getOriginalFilename(), PATHINFO_EXTENSION);

        return $extension === '' ? $document->getName() : $document->getName() . '.' . $extension;
    }

    #[Route('/documentos/{documentId}/revisiones/{revisionId}/aprobar', name: 'app_folder_document_revision_approve', methods: ['POST'])]
    public function approve(string $folderId, string $documentId, string $revisionId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $folder   = $this->requireFolder($folderId, $centre);
        $document = $this->requireDocument($documentId, $folder);
        $teacher  = $this->requireTeacher();
        $this->denyAccessUnlessGranted(FolderVoter::REVIEW, $folder);

        if (!$this->isCsrfTokenValid('folder_document_revision_review_' . $revisionId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $result = trim($request->request->getString('reviewResult'));
        $revision->approve($teacher, $result !== '' ? $result : null);
        $document->setActiveRevision($revision);
        $this->em->flush();

        $this->addFlash('success', $this->t('review.flash.approved'));

        return $this->redirectToFolder($folder, $request);
    }

    #[Route('/documentos/{documentId}/revisiones/{revisionId}/rechazar', name: 'app_folder_document_revision_reject', methods: ['POST'])]
    public function reject(string $folderId, string $documentId, string $revisionId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $folder   = $this->requireFolder($folderId, $centre);
        $document = $this->requireDocument($documentId, $folder);
        $teacher  = $this->requireTeacher();
        $this->denyAccessUnlessGranted(FolderVoter::REVIEW, $folder);

        if (!$this->isCsrfTokenValid('folder_document_revision_review_' . $revisionId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $result = trim($request->request->getString('reviewResult'));
        $revision->reject($teacher, $result !== '' ? $result : null);
        if ($document->getActiveRevision() === $revision) {
            $document->setActiveRevision(null);
        }
        $this->em->flush();

        $this->addFlash('success', $this->t('review.flash.rejected'));

        return $this->redirectToFolder($folder, $request);
    }

    private function requireFolder(string $folderId, EducationalCentre $centre): Folder
    {
        $folder = $this->folders->findById($folderId);
        if ($folder === null || $folder->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $folder;
    }

    private function requireDocument(string $documentId, Folder $folder): Document
    {
        $document = $this->documents->findByIdAndFolder($documentId, $folder);
        if ($document === null) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function requireTeacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * These routes are also submitted from the Actividades page (a submission's Document lives in
     * an activity's folder, but the folder itself may not be reachable from the document tree for
     * the current teacher, or the teacher simply wants to stay where they were) — redirect back to
     * wherever the form was submitted from when that's a same-origin page, falling back to the
     * document tree only when there's no usable referer.
     */
    private function redirectToFolder(Folder $folder, Request $request): Response
    {
        $referer = $request->headers->get('referer');
        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_document_tree', [
            'section' => $folder->getDocumentSection()->getId()->toRfc4122(),
            'folder'  => $folder->getId()->toRfc4122(),
        ]);
    }

    private function translationDomain(): string
    {
        return 'document_content';
    }
}
