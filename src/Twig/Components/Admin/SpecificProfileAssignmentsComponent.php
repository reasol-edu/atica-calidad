<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ProfileAssignmentRow;
use App\Pagination\Paginator;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ProfileAssignmentRowBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Cross-cutting view over a centre's specific-profile assignments, from two angles: every
 * profile/subperfil and who's assigned to it ("Perfiles"), or every teacher and what they're
 * assigned to ("Docentes"). Doesn't create/edit/delete profiles or lists — that stays in
 * SpecificProfileTreeComponent/ListItemTreeComponent; this is a working view over assignments that
 * already exist, built to spot stale ones (teachers no longer in the active academic year).
 */
#[AsLiveComponent]
class SpecificProfileAssignmentsComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    private const int PAGE_SIZE = 20;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $tab = 'profiles';

    // ── Tab "Perfiles" ──────────────────────────────────────────────────────
    #[LiveProp(writable: true)]
    public string $profileSearch = '';

    #[LiveProp(writable: true)]
    public bool $showInactiveProfiles = false;

    #[LiveProp(writable: true)]
    public int $profilePage = 1;

    #[LiveProp(writable: true)]
    public string $selectedRowKey = '';

    #[LiveProp(writable: true)]
    public bool $confirmingBulkRemove = false;

    // ── Tab "Docentes" ──────────────────────────────────────────────────────
    #[LiveProp(writable: true)]
    public string $teacherSearch = '';

    #[LiveProp(writable: true)]
    public bool $showAllYearsTeachers = false;

    #[LiveProp(writable: true)]
    public int $teacherPage = 1;

    #[LiveProp(writable: true)]
    public string $selectedTeacherId = '';

    #[LiveProp(writable: true)]
    public string $pickerSearch = '';

    /** @var ProfileAssignmentRow[]|null */
    private ?array $rowsCache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly TeacherRepository $teachers,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $this->centre = $centre;
    }

    /** Same rule as SpecificProfileTreeComponent: assignments need an active year to search candidate teachers against. */
    public function canWrite(): bool
    {
        return $this->centre->getActiveAcademicYear() !== null;
    }

    #[LiveAction]
    public function selectTab(#[LiveArg] string $tab): void
    {
        $this->tab = $tab === 'teachers' ? 'teachers' : 'profiles';
    }

    // ── Row building (shared by both tabs) ─────────────────────────────────

    /** @return ProfileAssignmentRow[] */
    private function getAllRows(): array
    {
        return $this->rowsCache ??= $this->rowBuilder->buildAllRows($this->centre);
    }

    private function findRowByKey(string $key): ?ProfileAssignmentRow
    {
        foreach ($this->getAllRows() as $row) {
            if ($row->key() === $key) {
                return $row;
            }
        }

        return null;
    }

    public function isTeacherOffYear(Teacher $teacher): bool
    {
        $year = $this->centre->getActiveAcademicYear();

        return $year === null || !$teacher->getAcademicYears()->contains($year);
    }

    // ── Tab "Perfiles" ──────────────────────────────────────────────────────

    /** @return Paginator<ProfileAssignmentRow> */
    public function getProfilePagination(): Paginator
    {
        $search = mb_strtolower(trim($this->profileSearch));

        $rows = array_values(array_filter($this->getAllRows(), function (ProfileAssignmentRow $row) use ($search): bool {
            if (!$this->showInactiveProfiles && !$row->active) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            if (str_contains(mb_strtolower($row->displayName), $search)) {
                return true;
            }
            foreach ($row->teachers as $teacher) {
                if (str_contains($this->searchableTeacherName($teacher), $search)) {
                    return true;
                }
            }

            return false;
        }));

        usort($rows, static fn (ProfileAssignmentRow $a, ProfileAssignmentRow $b): int => $a->displayName <=> $b->displayName);

        $page  = max(1, $this->profilePage);
        $slice = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return Paginator::fromArray($slice, count($rows), $page, self::PAGE_SIZE);
    }

    #[LiveAction]
    public function toggleShowInactiveProfiles(): void
    {
        $this->showInactiveProfiles = !$this->showInactiveProfiles;
        $this->profilePage          = 1;
    }

    #[LiveAction]
    public function setProfilePage(#[LiveArg] int $page): void
    {
        $this->profilePage = max(1, $page);
    }

    public function getSelectedRow(): ?ProfileAssignmentRow
    {
        return $this->selectedRowKey === '' ? null : $this->findRowByKey($this->selectedRowKey);
    }

    #[LiveAction]
    public function selectRow(#[LiveArg] string $key): void
    {
        $this->selectedRowKey       = $key;
        $this->confirmingBulkRemove = false;
    }

    #[LiveAction]
    public function clearRowSelection(): void
    {
        $this->selectedRowKey = '';
    }

    #[LiveAction]
    public function assignTeacherToRow(#[LiveArg] string $teacherId): void
    {
        $this->requireWritableCentre();
        $row = $this->getSelectedRow();
        if ($row === null) {
            return;
        }

        $year    = $this->centre->getActiveAcademicYear();
        $teacher = $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $teacherId);
        if ($teacher === null) {
            return;
        }

        $row->profile->addAssignment($teacher, $row->listItem);
        $this->em->flush();
        $this->rowsCache = null;
        $this->flashSuccess($this->t('responsibilities.assignments.flash.teacher_assigned'));
    }

    #[LiveAction]
    public function removeTeacherFromRow(#[LiveArg] string $teacherId): void
    {
        $this->requireWritableCentre();
        $row = $this->getSelectedRow();
        if ($row === null) {
            return;
        }

        foreach ($row->profile->getAssignments() as $assignment) {
            if ($assignment->getTeacher()->getId()->toRfc4122() === $teacherId && $assignment->getListItem() === $row->listItem) {
                $row->profile->removeAssignment($assignment);
            }
        }
        $this->em->flush();
        $this->rowsCache = null;
        $this->flashSuccess($this->t('responsibilities.assignments.flash.teacher_removed'));
    }

    public function getOffYearAssignmentCount(): int
    {
        $count = 0;
        foreach ($this->getAllRows() as $row) {
            if (!$row->active) {
                continue;
            }
            foreach ($row->teachers as $teacher) {
                if ($this->isTeacherOffYear($teacher)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    #[LiveAction]
    public function askBulkRemoveOffYear(): void
    {
        $this->confirmingBulkRemove = true;
    }

    #[LiveAction]
    public function cancelBulkRemove(): void
    {
        $this->confirmingBulkRemove = false;
    }

    #[LiveAction]
    public function bulkRemoveOffYear(): void
    {
        $this->requireWritableCentre();
        $removed = 0;
        foreach ($this->getAllRows() as $row) {
            if (!$row->active) {
                continue;
            }
            foreach ($row->profile->getAssignments() as $assignment) {
                if ($assignment->getListItem() !== $row->listItem) {
                    continue;
                }
                if ($this->isTeacherOffYear($assignment->getTeacher())) {
                    $row->profile->removeAssignment($assignment);
                    $removed++;
                }
            }
        }
        $this->em->flush();
        $this->rowsCache            = null;
        $this->confirmingBulkRemove = false;
        $this->flashSuccess($this->translator->trans('responsibilities.assignments.flash.bulk_removed', ['%count%' => $removed], 'admin'));
    }

    // ── Tab "Docentes" ──────────────────────────────────────────────────────

    /**
     * One entry per teacher visible in this tab: current-year teachers always, plus (when
     * $showAllYearsTeachers) any other teacher who still has an assignment here.
     *
     * @return array<int, array{teacher: Teacher, inActiveYear: bool, rows: ProfileAssignmentRow[]}>
     */
    private function getAllTeacherEntries(): array
    {
        $rowsByTeacher = [];
        foreach ($this->getAllRows() as $row) {
            foreach ($row->teachers as $teacher) {
                $id                              = $teacher->getId()->toRfc4122();
                $rowsByTeacher[$id]['teacher'] ??= $teacher;
                $rowsByTeacher[$id]['rows'][]      = $row;
            }
        }

        $entries = [];
        $seen    = [];
        $year    = $this->centre->getActiveAcademicYear();

        if ($year !== null) {
            foreach ($this->teachers->findByAcademicYearOrderedByName($year) as $teacher) {
                $id        = $teacher->getId()->toRfc4122();
                $seen[$id] = true;
                $entries[] = ['teacher' => $teacher, 'inActiveYear' => true, 'rows' => $rowsByTeacher[$id]['rows'] ?? []];
            }
        }

        if ($this->showAllYearsTeachers) {
            foreach ($rowsByTeacher as $id => $data) {
                if (isset($seen[$id])) {
                    continue;
                }
                $entries[] = ['teacher' => $data['teacher'], 'inActiveYear' => false, 'rows' => $data['rows']];
            }
        }

        return $entries;
    }

    /** @return Paginator<array{teacher: Teacher, inActiveYear: bool, rows: ProfileAssignmentRow[]}> */
    public function getTeacherPagination(): Paginator
    {
        $search = mb_strtolower(trim($this->teacherSearch));

        $entries = array_values(array_filter($this->getAllTeacherEntries(), function (array $entry) use ($search): bool {
            if ($search === '') {
                return true;
            }
            if (str_contains($this->searchableTeacherName($entry['teacher']), $search)) {
                return true;
            }
            foreach ($entry['rows'] as $row) {
                if (str_contains(mb_strtolower($row->displayName), $search)) {
                    return true;
                }
            }

            return false;
        }));

        usort($entries, static function (array $a, array $b): int {
            $an = $a['teacher']->getName();
            $bn = $b['teacher']->getName();

            return [$an->getLastName(), $an->getFirstName()] <=> [$bn->getLastName(), $bn->getFirstName()];
        });

        $page  = max(1, $this->teacherPage);
        $slice = array_slice($entries, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return Paginator::fromArray($slice, count($entries), $page, self::PAGE_SIZE);
    }

    #[LiveAction]
    public function toggleShowAllYearsTeachers(): void
    {
        $this->showAllYearsTeachers = !$this->showAllYearsTeachers;
        $this->teacherPage          = 1;
    }

    #[LiveAction]
    public function setTeacherPage(#[LiveArg] int $page): void
    {
        $this->teacherPage = max(1, $page);
    }

    /** @return array{teacher: Teacher, inActiveYear: bool, rows: ProfileAssignmentRow[]}|null */
    public function getSelectedTeacherEntry(): ?array
    {
        if ($this->selectedTeacherId === '') {
            return null;
        }

        foreach ($this->getAllTeacherEntries() as $entry) {
            if ($entry['teacher']->getId()->toRfc4122() === $this->selectedTeacherId) {
                return $entry;
            }
        }

        return null;
    }

    #[LiveAction]
    public function selectTeacher(#[LiveArg] string $id): void
    {
        $this->selectedTeacherId = $id;
        $this->pickerSearch      = '';
    }

    #[LiveAction]
    public function clearTeacherSelection(): void
    {
        $this->selectedTeacherId = '';
    }

    /** @return ProfileAssignmentRow[] active rows the selected teacher isn't already assigned to */
    public function getAssignableRowsForTeacher(): array
    {
        $entry = $this->getSelectedTeacherEntry();
        if ($entry === null) {
            return [];
        }

        $assignedKeys = array_map(static fn (ProfileAssignmentRow $row): string => $row->key(), $entry['rows']);
        $search       = mb_strtolower(trim($this->pickerSearch));

        return array_values(array_filter($this->getAllRows(), function (ProfileAssignmentRow $row) use ($assignedKeys, $search): bool {
            if (!$row->active || in_array($row->key(), $assignedKeys, true)) {
                return false;
            }

            return $search === '' || str_contains(mb_strtolower($row->displayName), $search);
        }));
    }

    #[LiveAction]
    public function assignRowToTeacher(#[LiveArg] string $rowKey): void
    {
        $this->requireWritableCentre();
        $entry = $this->getSelectedTeacherEntry();
        $row   = $this->findRowByKey($rowKey);
        if ($entry === null || $row === null || !$row->active) {
            return;
        }

        $row->profile->addAssignment($entry['teacher'], $row->listItem);
        $this->em->flush();
        $this->rowsCache    = null;
        $this->pickerSearch = '';
        $this->flashSuccess($this->t('responsibilities.assignments.flash.teacher_assigned'));
    }

    #[LiveAction]
    public function removeRowFromTeacher(#[LiveArg] string $rowKey): void
    {
        $this->requireWritableCentre();
        $entry = $this->getSelectedTeacherEntry();
        $row   = $this->findRowByKey($rowKey);
        if ($entry === null || $row === null) {
            return;
        }

        foreach ($row->profile->getAssignments() as $assignment) {
            if ($assignment->getTeacher() === $entry['teacher'] && $assignment->getListItem() === $row->listItem) {
                $row->profile->removeAssignment($assignment);
            }
        }
        $this->em->flush();
        $this->rowsCache = null;
        $this->flashSuccess($this->t('responsibilities.assignments.flash.teacher_removed'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function searchableTeacherName(Teacher $teacher): string
    {
        return mb_strtolower($teacher->getName()->getLastName() . ' ' . $teacher->getName()->getFirstName());
    }

    private function requireWritableCentre(): EducationalCentre
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        if (!$this->canWrite()) {
            throw $this->createAccessDeniedException();
        }

        return $this->centre;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    /**
     * LiveAction responses only re-render this component's fragment, not the layout, so a plain
     * addFlash() never reaches the page until the next full navigation. Dispatch a browser event
     * instead so the layout's JS can render the flash immediately.
     */
    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }
}
