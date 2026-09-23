<?php

declare(strict_types=1);

namespace App\Model;

/** One document of the master list (see DocumentMasterListBuilder). */
final readonly class DocumentMasterListRow
{
    /** Published: it has an approved, active revision. */
    public const string STATUS_ACTIVE = 'active';
    /** Published, with a newer revision waiting for approval. */
    public const string STATUS_UPDATING = 'updating';
    /** Not published yet: its only revision waits for approval. */
    public const string STATUS_PENDING = 'pending';
    /** Not published: no approved revision (the last one was rejected). */
    public const string STATUS_NONE = 'none';

    public function __construct(
        /** "Sección › Subsección" */
        public string $sectionPath,
        public string $folderName,
        public string $documentName,
        public string $status,
        /** Of the active revision; null when there's none. */
        public ?int $version,
        public ?\DateTimeImmutable $versionDate,
        public ?string $uploadedBy,
        /** The folder's responsible profiles, "Perfil subperfil, …" ("" when it has none). */
        public string $responsibles,
        public ?\DateTimeImmutable $nextReviewAt,
        /** DocumentReviewSchedule state of $nextReviewAt, or null. */
        public ?string $reviewState,
    ) {}
}
