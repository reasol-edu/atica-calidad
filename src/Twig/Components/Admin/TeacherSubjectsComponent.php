<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\GroupTeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\TenantContext;
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
 * Inline editor for a single teacher's subjects ("materias"): each row pairs
 * a group with the free-text subject taught there (GroupTeacher), scoped to
 * the centre's active academic year. "Modify" is implemented as a guarded
 * remove-and-add, since GroupTeacher has no setters.
 */
#[AsLiveComponent]
class TeacherSubjectsComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp]
    public Teacher $teacher;

    #[LiveProp(writable: true)]
    public string $newGroupId = '';

    #[LiveProp(writable: true)]
    public string $newSubject = '';

    #[LiveProp(writable: true)]
    public string $editingId = '';

    #[LiveProp(writable: true)]
    public string $editGroupId = '';

    #[LiveProp(writable: true)]
    public string $editSubject = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly GroupRepository $groups,
        private readonly GroupTeacherRepository $groupTeachers,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre, Teacher $teacher): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->centre  = $centre;
        $this->teacher = $teacher;
    }

    public function canWrite(): bool
    {
        return !$this->tenantContext->isViewingNonActiveYear($this->centre);
    }

    // ── Data ─────────────────────────────────────────────────────────────────

    /** @return GroupTeacher[] */
    public function getAssignments(): array
    {
        $year = $this->centre->getActiveAcademicYear();

        return $year === null ? [] : $this->groupTeachers->findByTeacherAndAcademicYearOrdered($this->teacher, $year);
    }

    /** @return Group[] */
    public function getGroupOptions(): array
    {
        return $this->groups->findByActiveYearOfCentreWithCourse($this->centre);
    }

    private function resolveGroup(string $id): ?Group
    {
        foreach ($this->getGroupOptions() as $group) {
            if ($group->getId()->toRfc4122() === $id) {
                return $group;
            }
        }

        return null;
    }

    private function findAssignment(string $id): ?GroupTeacher
    {
        foreach ($this->getAssignments() as $assignment) {
            if ($assignment->getId()->toRfc4122() === $id) {
                return $assignment;
            }
        }

        return null;
    }

    // ── Add ──────────────────────────────────────────────────────────────────

    #[LiveAction]
    public function addSubject(): void
    {
        $this->requireWritableCentre();
        $subject = trim($this->newSubject);
        $group   = $this->resolveGroup($this->newGroupId);

        if ($group === null || $subject === '') {
            $this->errors = ['add' => $this->t('group.error.teacher_subject_required')];

            return;
        }

        if ($group->hasTeacherSubject($this->teacher, $subject)) {
            $this->errors = ['add' => $this->t('group.error.teacher_subject_duplicate')];

            return;
        }

        $group->addTeacher($this->teacher, $subject);
        $this->em->flush();

        $this->newGroupId = $this->newSubject = '';
        $this->errors = [];
        $this->flashSuccess($this->t('centre_teachers.subjects.flash.saved'));
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    #[LiveAction]
    public function startEdit(#[LiveArg] string $id): void
    {
        $assignment = $this->findAssignment($id);
        if ($assignment === null) {
            return;
        }

        $this->editingId   = $id;
        $this->editGroupId = $assignment->getGroup()->getId()->toRfc4122();
        $this->editSubject = $assignment->getSubject();
        $this->errors      = [];
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingId = '';
        $this->errors    = [];
    }

    #[LiveAction]
    public function saveEdit(): void
    {
        $this->requireWritableCentre();
        $assignment = $this->findAssignment($this->editingId);
        if ($assignment === null) {
            return;
        }

        $newGroup   = $this->resolveGroup($this->editGroupId);
        $newSubject = trim($this->editSubject);

        if ($newGroup === null || $newSubject === '') {
            $this->errors = ['edit' => $this->t('group.error.teacher_subject_required')];

            return;
        }

        if ($newGroup === $assignment->getGroup() && $newSubject === $assignment->getSubject()) {
            $this->editingId = '';
            $this->errors    = [];

            return;
        }

        if ($newGroup->hasTeacherSubject($this->teacher, $newSubject)) {
            $this->errors = ['edit' => $this->t('group.error.teacher_subject_duplicate')];

            return;
        }

        $assignment->getGroup()->removeTeacherAssignment($assignment);
        $newGroup->addTeacher($this->teacher, $newSubject);
        $this->em->flush();

        $this->editingId = '';
        $this->errors = [];
        $this->flashSuccess($this->t('centre_teachers.subjects.flash.saved'));
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    #[LiveAction]
    public function deleteSubject(#[LiveArg] string $id): void
    {
        $this->requireWritableCentre();
        $assignment = $this->findAssignment($id);
        if ($assignment === null) {
            return;
        }

        $assignment->getGroup()->removeTeacherAssignment($assignment);
        $this->em->flush();

        if ($this->editingId === $id) {
            $this->editingId = '';
        }
        $this->flashSuccess($this->t('centre_teachers.subjects.flash.deleted'));
    }

    private function requireWritableCentre(): EducationalCentre
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $this->centre);
        if (!$this->canWrite() || $this->centre->getActiveAcademicYear() === null) {
            throw $this->createAccessDeniedException();
        }

        return $this->centre;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    /**
     * LiveAction responses only re-render this component's fragment, not the
     * layout, so a plain addFlash() never reaches the page until the next
     * full navigation. Dispatch a browser event instead so the layout's JS
     * can render the flash immediately.
     */
    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }
}
