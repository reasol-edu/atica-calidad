<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\DocumentSectionProfile;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FolderResponsibleProfile;
use App\Entity\FolderReviewProfile;
use App\Entity\FolderUploadProfile;
use App\Entity\FolderVisibilityProfile;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\ProfileAssignmentRow;
use App\Repository\SpecificProfileAssignmentRepository;

/**
 * Centralises the profile-based access rules shared by document-section navigation and folder
 * permissions: admin/responsable de calidad can do anything; auditor/a interno/a can always read,
 * even what's restricted or obsolete; otherwise access depends on whether the teacher currently
 * holds one of the entity's associated profiles/subperfiles (no restriction at all = open to
 * everyone). Restrictions never cascade from a section to its subsections or folders — each node
 * is evaluated on its own.
 */
final class DocumentTreeAccessChecker
{
    /** @var array<string, bool> memoizes holdsProfile() per (teacher, profile, list item) for this request — see its own docblock */
    private array $holdsProfileCache = [];

    public function __construct(
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
    ) {}

    public function isAdminOrQualityManager(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $teacher->isAdmin() || $centre->getAdmins()->contains($teacher) || $centre->getQualityManagers()->contains($teacher);
    }

    public function isInternalAuditor(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $centre->getInternalAuditors()->contains($teacher);
    }

    public function canViewSection(Teacher $teacher, DocumentSection $section): bool
    {
        $centre = $section->getEducationalCentre();
        if ($this->isAdminOrQualityManager($teacher, $centre) || $this->isInternalAuditor($teacher, $centre)) {
            return true;
        }

        if ($section->getProfileRestrictions()->isEmpty()) {
            return true;
        }

        /** @var array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs */
        $pairs = array_map(
            static fn (DocumentSectionProfile $r): array => [$r->getSpecificProfile(), $r->getListItem()],
            $section->getProfileRestrictions()->toArray()
        );

        return $this->assignments->isTeacherAssignedToAny($teacher, $pairs);
    }

    public function canViewFolder(Teacher $teacher, Folder $folder): bool
    {
        $centre = $folder->getEducationalCentre();
        if ($this->isAdminOrQualityManager($teacher, $centre) || $this->isInternalAuditor($teacher, $centre)) {
            return true;
        }

        if (!$folder->isVisibilityRestricted()) {
            return true;
        }

        /** @var array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs */
        $pairs = array_map(
            static fn (FolderVisibilityProfile $r): array => [$r->getSpecificProfile(), $r->getListItem()],
            $folder->getVisibilityProfiles()->toArray()
        );

        return $this->assignments->isTeacherAssignedToAny($teacher, $pairs);
    }

    /**
     * Whether the teacher could reach this document by browsing the tree — the folder's own
     * visibility restrictions AND every ancestor section's own restrictions (checked
     * independently, since restrictions never cascade: a document isn't surfaced by search unless
     * each level on the way to it would itself let the teacher through).
     */
    public function canViewDocument(Teacher $teacher, Document $document): bool
    {
        $folder = $document->getFolder();
        if (!$this->canViewFolder($teacher, $folder)) {
            return false;
        }

        for ($section = $folder->getDocumentSection(); $section !== null; $section = $section->getParent()) {
            if (!$this->canViewSection($teacher, $section)) {
                return false;
            }
        }

        return true;
    }

    public function canManageFolder(Teacher $teacher, Folder $folder): bool
    {
        $centre = $folder->getEducationalCentre();
        if ($this->isAdminOrQualityManager($teacher, $centre)) {
            return true;
        }

        /** @var array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs */
        $pairs = array_map(
            static fn (FolderResponsibleProfile $r): array => [$r->getSpecificProfile(), $r->getListItem()],
            $folder->getResponsibleProfiles()->toArray()
        );

        return $this->assignments->isTeacherAssignedToAny($teacher, $pairs);
    }

    public function canUploadToFolder(Teacher $teacher, Folder $folder): bool
    {
        if ($this->canManageFolder($teacher, $folder)) {
            return true;
        }

        /** @var array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs */
        $pairs = array_map(
            static fn (FolderUploadProfile $r): array => [$r->getSpecificProfile(), $r->getListItem()],
            $folder->getUploadProfiles()->toArray()
        );

        return $this->assignments->isTeacherAssignedToAny($teacher, $pairs);
    }

    /**
     * Whether the teacher may add a new revision to this specific document, or delete it outright
     * (with all its revisions). A folder responsible always can; the sole exception for anyone
     * else is the teacher who uploaded the document's current active revision — that alone does
     * not let them view/manage the rest of its revision history, change which revision is active,
     * or delete an individual revision (see canManageFolder(), canEditRevisionMetadata()).
     */
    public function canManageDocumentAsUploader(Teacher $teacher, Document $document): bool
    {
        if ($this->canManageFolder($teacher, $document->getFolder())) {
            return true;
        }

        return $document->getActiveRevision()?->getUploadedBy() === $teacher;
    }

    public function canReviewFolder(Teacher $teacher, Folder $folder): bool
    {
        if ($this->canManageFolder($teacher, $folder)) {
            return true;
        }

        return $this->holdsReviewProfile($teacher, $folder);
    }

    /**
     * Whether the teacher personally holds one of the folder's own review profiles — unlike
     * canReviewFolder(), never broadened by canManageFolder()'s admin/quality-manager bypass. Used
     * to build a teacher's *personal* "pending review" queue (notification bell, dashboard
     * widget): an admin/quality manager who doesn't hold any review profile themselves is entitled
     * to review anything, but that's a different question from what's personally theirs to act on
     * — see PendingReviewFinder::allPendingForCentre() for the admin-facing "everything" view.
     */
    public function holdsReviewProfile(Teacher $teacher, Folder $folder): bool
    {
        /** @var array<int, array{0: SpecificProfile, 1: ?ListItem}> $pairs */
        $pairs = array_map(
            static fn (FolderReviewProfile $r): array => [$r->getSpecificProfile(), $r->getListItem()],
            $folder->getReviewProfiles()->toArray()
        );

        return $this->assignments->isTeacherAssignedToAny($teacher, $pairs);
    }

    /**
     * Whether the teacher personally holds this exact profile/subperfil (used to constrain
     * upload-as picker choices). Memoized per (teacher, profile, list item) for this request — a
     * loop checking the same handful of profiles across many activities/documents (e.g. the
     * "Mis actividades" tab) would otherwise re-run the identical query once per iteration. Safe
     * without invalidation: nothing that changes a SpecificProfileAssignment (see
     * ProfileAssignmentRowBuilder's own docblock for the full list) reads through this checker
     * again in the same request.
     */
    public function holdsProfile(Teacher $teacher, SpecificProfile $profile, ?ListItem $listItem): bool
    {
        $key = $teacher->getId()->toRfc4122() . '|' . $profile->getId()->toRfc4122() . '|' . ($listItem?->getId()->toRfc4122() ?? '');

        return $this->holdsProfileCache[$key] ??= $this->assignments->isTeacherAssignedToAny($teacher, [[$profile, $listItem]]);
    }

    /**
     * Which of a folder's own upload-profile rows this teacher may tag a document with when
     * uploading: all of them for a folder responsible/quality-manager/admin, only the ones the
     * teacher personally holds otherwise — see the folder's own definition of "responsible" (can
     * pick freely) vs a plain uploader (can only tag with their own subperfil).
     *
     * Uses buildActiveRowsWithWholeProfileOption() (not the plain per-subperfil buildActiveRows())
     * because a folder's upload restriction can itself be the "(todos)" wildcard — a profile
     * picked without pinning it to one subperfil (see Folder settings / ProfileAssignmentRowBuilder).
     * folderAcceptsUploadRow() understands that wildcard: it matches every subperfil of that
     * profile, plus the profile-wide "(todos)" tag itself.
     *
     * @return ProfileAssignmentRow[]
     */
    public function allowedUploadProfileRows(Teacher $teacher, Folder $folder): array
    {
        $canManage = $this->canManageFolder($teacher, $folder);
        $rows      = [];
        foreach ($this->rowBuilder->buildActiveRowsWithWholeProfileOption($folder->getEducationalCentre()) as $row) {
            if (!$this->folderAcceptsUploadRow($folder, $row)) {
                continue;
            }
            if (!$canManage && !$this->holdsProfile($teacher, $row->profile, $row->listItem)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A row is offerable for tagging an upload when the folder restricts uploads to it exactly, or
     * — for a subperfil row — when the folder instead restricts uploads to "any subperfil" of that
     * same profile (the wildcard/"(todos)" pick, stored as (profile, null)).
     */
    private function folderAcceptsUploadRow(Folder $folder, ProfileAssignmentRow $row): bool
    {
        if ($folder->hasUploadProfile($row->profile, $row->listItem)) {
            return true;
        }

        return $row->listItem !== null && $folder->hasUploadProfile($row->profile, null);
    }

    /**
     * Every concrete upload-profile/subperfil row this folder accepts, regardless of any
     * particular teacher — unlike allowedUploadProfileRows(), this never includes the profile-wide
     * "(todos)" wildcard row itself: a wildcard restriction is expanded here into each of its
     * concrete subperfil rows instead (via folderAcceptsUploadRow()'s own understanding of the
     * wildcard), since this is meant for grouping by who could actually hold each row — nobody
     * holds "(todos)" itself. Used by ActivitySubmissionSlotBuilder to enumerate an activity's
     * expected submissions.
     *
     * @return ProfileAssignmentRow[]
     */
    public function getFolderUploadRows(Folder $folder): array
    {
        $rows = [];
        foreach ($this->rowBuilder->buildActiveRows($folder->getEducationalCentre()) as $row) {
            if ($this->folderAcceptsUploadRow($folder, $row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Whether an activity is relevant to this teacher for browsing purposes — they manage, can
     * upload to, or can review its folder. Deliberately narrower than canViewFolder(): visibility
     * restrictions are a real access gate (checked separately, always), this is just "would this
     * teacher normally expect to see this in their list" — the "Mostrar lo de todos los perfiles"
     * toggle bypasses only this relevance check, never the underlying visibility. An activity
     * without a folder (a plain manual reminder, no profiles to consult) is always relevant.
     */
    public function isActivityRelevantToTeacher(Teacher $teacher, Activity $activity): bool
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return true;
        }

        return $this->canManageFolder($teacher, $folder)
            || $this->canUploadToFolder($teacher, $folder)
            || $this->canReviewFolder($teacher, $folder);
    }
}
