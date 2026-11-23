<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\FormAttachment;
use App\Entity\FormSubmission;
use App\Entity\Teacher;
use App\Repository\FormAttachmentRepository;
use App\Service\DocumentCreationService;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The files of the fields «Adjuntos»: uploading one to a draft (checked by content, size and room left), and keeping the data honest —
 * a value may only point at attachments of its own submission, with the name, size and type they really have.
 */
final class FormAttachmentService
{
    /** Office formats, by extension: libmagic sometimes reads them as a plain ZIP (they are one). */
    private const array ZIPPED = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormAttachmentRepository $attachments,
        private readonly DocumentCreationService $documents,
        private readonly UploadLimits $limits,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Stores a file for the field `$fieldKey` of a draft. The draft's data does not change here: the page adds the file to the field and
     * saves, like any other value.
     *
     * @param array<string, mixed> $definition the draft's (FormSubmissionService::definitionOf())
     *
     * @throws FormException `error.locked`, `attachment.not_a_field`, `attachment.too_large`, `attachment.type`, `attachment.upload`
     */
    public function attach(FormSubmission $submission, array $definition, string $fieldKey, UploadedFile $file, Teacher $by): FormAttachment
    {
        if (!$submission->hasDraft()) {
            throw new FormException('error.no_draft');
        }
        $holder = $submission->getLockHolder($this->clock->now());
        if ($holder !== null && !$holder->getId()->equals($by->getId())) {
            throw new FormException('error.locked');
        }
        $block = FormIndex::of($definition)->fields[$fieldKey] ?? null;
        if ($block === null || ($block['fieldType'] ?? '') !== 'attachment') {
            throw new FormException('attachment.not_a_field');
        }
        if (!$file->isValid() || $this->limits->validate([$file]) !== []) {
            throw new FormException('attachment.upload');
        }
        if ((int) $file->getSize() > min(FormDefinition::ATTACHMENT_MAX_BYTES, $this->limits->maxFileBytes())) {
            throw new FormException('attachment.too_large', [['code' => 'size', 'params' => ['%max%' => FormAttachments::size(min(FormDefinition::ATTACHMENT_MAX_BYTES, $this->limits->maxFileBytes()))]]]);
        }
        $content = (string) file_get_contents($file->getPathname());
        $mime    = $this->typeOf($file, $content);
        if ($mime === null) {
            throw new FormException('attachment.type');
        }

        $name       = trim(str_replace(["\r", "\n", '/', '\\'], ' ', $file->getClientOriginalName())) ?: 'adjunto';
        $stored     = $this->documents->storeFile($content, $mime, $name);
        $attachment = new FormAttachment($submission, $stored, $name, $by, $this->clock->now());
        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    /**
     * The data with every attachment field holding only attachments of this submission, as they really are (a browser cannot point at
     * another document's file, rename one or change its size).
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function restrict(FormSubmission $submission, array $definition, array $data): array
    {
        $ids = FormAttachments::ids($definition, $data);
        if ($ids === []) {
            return $data;
        }
        $own = $this->attachments->findOfSubmission($submission, $ids);

        return FormAttachments::map($definition, $data, static function (array $files, array $block) use ($own): array {
            $out = [];
            foreach ($files as $file) {
                $attachment = $own[FormDefinition::str($file['id'] ?? '')] ?? null;
                if ($attachment !== null) {
                    $out[] = $attachment->toValue();
                }
            }

            // As many as the field takes, the first ones.
            return array_slice($out, 0, FormDefinition::int($block['maxFiles'] ?? FormDefinition::ATTACHMENT_DEFAULT_FILES) ?: FormDefinition::ATTACHMENT_DEFAULT_FILES);
        });
    }

    /**
     * The files the data points at, with their content, by id: what the PDF of a delivery draws, embeds and appends (FormPdfBuilder).
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, array{name: string, mime: string, content: string}>
     */
    public function contents(FormSubmission $submission, array $definition, array $data): array
    {
        $out = [];
        foreach ($this->attachments->findOfSubmission($submission, FormAttachments::ids($definition, $data)) as $id => $attachment) {
            $out[$id] = ['name' => $attachment->getFilename(), 'mime' => $attachment->getMimeType(), 'content' => $attachment->getFile()->getContent()];
        }

        return $out;
    }

    /** What a file is, by its content (never by its name), when it is one of FormDefinition::ATTACHMENT_TYPES; null otherwise. */
    private function typeOf(UploadedFile $file, string $content): ?string
    {
        $mime = (string) $file->getMimeType();
        if (in_array($mime, FormDefinition::ATTACHMENT_TYPES, true)) {
            return $mime;
        }
        // An office document read as a ZIP: its extension says which, if it really is a ZIP.
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && isset(self::ZIPPED[$extension]) && str_starts_with($content, "PK\x03\x04")) {
            return self::ZIPPED[$extension];
        }
        // CSV is often read as plain text with another name.
        if ($mime === 'text/x-csv' || ($extension === 'csv' && str_starts_with($mime, 'text/'))) {
            return 'text/csv';
        }

        return null;
    }
}
