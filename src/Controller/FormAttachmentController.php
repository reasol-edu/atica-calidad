<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\FormAttachment;
use App\Entity\Teacher;
use App\Repository\DocumentFileRepository;
use App\Repository\FormAttachmentRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Security\Voter\FolderVoter;
use App\Service\AttachmentDownloadResponder;
use App\Service\Form\FormAttachments;
use App\Service\Form\FormListOptions;
use App\Service\Form\FormResolver;
use App\Service\Form\FormSlotAccess;
use App\Service\Form\FormSubmissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The files of the fields «Adjuntos» of the filled documents: one by one, and a delivered document with all of them in a ZIP.
 *
 * Who sees a file: whoever may fill the slot (while it is a draft or after), and whoever may see the folder the document was delivered
 * to — but only the files of a delivered revision, never one that is only in a draft.
 */
final class FormAttachmentController extends AbstractController
{
    public function __construct(
        private readonly FormAttachmentRepository $attachments,
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly DocumentFileRepository $files,
        private readonly FormSubmissionService $service,
        private readonly FormSlotAccess $slotAccess,
        private readonly FormResolver $resolver,
        private readonly FormListOptions $lists,
        private readonly AttachmentDownloadResponder $downloads,
    ) {}

    #[Route('/formularios/adjunto/{id}', name: 'app_form_attachment', methods: ['GET'])]
    public function download(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $attachment = $this->attachments->findById($id);
        if ($attachment === null || !$this->canSee($attachment, $centre)) {
            throw $this->createNotFoundException();
        }
        $file = $attachment->getFile();

        return $this->downloads->respond($file->getContent(), $attachment->getMimeType(), $attachment->getFilename(), $request->query->getBoolean('ver'));
    }

    /** A delivered document with its annexes: the PDF and a folder «anexos» with the files numbered as the document numbers them. */
    #[Route('/formularios/revision/{revisionId}/con-anexos.zip', name: 'app_form_revision_zip', methods: ['GET'])]
    public function zip(string $revisionId, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $filled = $this->revisions->findByRevisionId($revisionId);
        if ($filled === null) {
            throw $this->createNotFoundException();
        }
        $document = $filled->getRevision()->getDocument();
        if ($document->getFolder()->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(FolderVoter::VIEW, $document->getFolder());

        $annexes = FormAttachments::fromResolved($this->resolver->resolve($this->lists->delivered($filled), $filled->getData()));
        $own     = $this->attachments->findOfSubmission($filled->getSubmission(), array_column($annexes, 'id'));
        $name    = self::safe($document->getName()) ?: 'documento';

        $path = tempnam(sys_get_temp_dir(), 'anexos');
        $zip  = new \ZipArchive();
        if ($path === false || $zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create the ZIP of the annexes.');
        }
        // One file at a time on disk: the contents are never all in memory (see DocumentFileRepository::writeContentTo()).
        $temps = [];
        $pdf   = (string) tempnam(sys_get_temp_dir(), 'doc');
        $this->files->writeContentTo($filled->getRevision()->getFile()->getId(), $pdf);
        $zip->addFile($pdf, $name . '.pdf');
        $temps[] = $pdf;
        foreach ($annexes as $annex) {
            $attachment = $own[$annex['id']] ?? null;
            if ($attachment === null) {
                continue;
            }
            $temp = (string) tempnam(sys_get_temp_dir(), 'anx');
            $this->files->writeContentTo($attachment->getFile()->getId(), $temp);
            $zip->addFile($temp, sprintf('anexos/%02d-%s', $annex['n'], self::safe($attachment->getFilename()) ?: 'adjunto'));
            $temps[] = $temp;
        }
        $zip->close();
        foreach ($temps as $temp) {
            @unlink($temp);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name . '-con-anexos.zip', 'documento-con-anexos.zip');
        $response->deleteFileAfterSend();

        return $response;
    }

    private function canSee(FormAttachment $attachment, EducationalCentre $centre): bool
    {
        $submission = $attachment->getSubmission();
        $activity   = $submission->getActivity();
        if ($activity->getCategory()->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            return false;
        }
        $teacher = $this->getUser();
        if (!$teacher instanceof Teacher) {
            return false;
        }
        $slot = $this->service->slotFor($activity, $submission->getSlotKey());
        if ($slot !== null && $this->slotAccess->canFill($teacher, $activity, $slot)) {
            return true;
        }
        // Whoever sees the folder sees what was delivered there, not what is still a draft.
        $document = $submission->getDocument();
        if ($document === null || !$this->isGranted(FolderVoter::VIEW, $document->getFolder())) {
            return false;
        }
        $id = $attachment->getId()->toRfc4122();
        foreach ($this->revisions->findBySubmission($submission) as $filled) {
            if (in_array($id, FormAttachments::ids($filled->getModelRevision()->getDefinition(), $filled->getData()), true)) {
                return true;
            }
        }

        return false;
    }

    /** A name for a file inside the ZIP: no folders, no control characters. */
    private static function safe(string $name): string
    {
        return trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/', '-', $name));
    }
}
