<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\Teacher;
use App\Repository\CourseRepository;
use App\Repository\GroupRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\TenantContext;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Column navigation (Miller columns) for the formative offer:
 * Courses → Groups, fully inline with no page reloads.
 */
#[AsLiveComponent]
class OfferTreeComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $courseId = '';

    #[LiveProp(writable: true)]
    public string $groupId = '';

    /** Inline "add" inputs, one per column. */
    #[LiveProp(writable: true)]
    public string $addCourseName = '';

    #[LiveProp(writable: true)]
    public string $addGroupName = '';

    /** Inline "add teacher" mini-form in the group detail panel. */
    #[LiveProp(writable: true)]
    public string $newTeacherId = '';

    #[LiveProp(writable: true)]
    public string $newTeacherSubject = '';

    /** Detail-panel fields for the deepest selected item. */
    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public string $editDetails = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    /** Inline two-step confirmation for deleting the selected item. */
    #[LiveProp(writable: true)]
    public bool $confirmingDelete = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly CourseRepository $courses,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->centre = $centre;
    }

    public function canWrite(): bool
    {
        return !$this->tenantContext->isViewingNonActiveYear($this->centre);
    }

    // ── Column data ──────────────────────────────────────────────────────────

    /** @return Course[] */
    public function getCourseList(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return [];
        }

        return $this->courses->findByAcademicYearOrdered($year);
    }

    /** @return array<string, int> */
    public function getCourseCounts(): array
    {
        return $this->courses->countByCourse($this->getCourseList());
    }

    /** @return Group[] */
    public function getGroupList(): array
    {
        $course = $this->getSelectedCourse();

        return $course === null ? [] : $this->groups->findByCourseOrderedByName($course);
    }

    /** @return array<string, array{teachers: int}> */
    public function getGroupCounts(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return [];
        }

        return $this->groups->findCountsByAcademicYear($year, $this->getGroupList());
    }

    /** @return Teacher[] */
    public function getTeacherOptions(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? [] : $this->teachers->findByAcademicYearOrderedByName($year);
    }

    // ── Selected entities ────────────────────────────────────────────────────

    public function getSelectedCourse(): ?Course
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null || $this->courseId === '') {
            return null;
        }

        return $this->courses->findByAcademicYearAndId($year, $this->courseId);
    }

    public function getSelectedGroup(): ?Group
    {
        if ($this->groupId === '') {
            return null;
        }

        return $this->groups->findByIdAndCentre($this->groupId, $this->centre);
    }

    /**
     * @return array{type: string, entity: Course|Group}|null
     */
    public function getSelected(): ?array
    {
        if (($group = $this->getSelectedGroup()) !== null) {
            return ['type' => 'group', 'entity' => $group];
        }
        if (($course = $this->getSelectedCourse()) !== null) {
            return ['type' => 'course', 'entity' => $course];
        }

        return null;
    }

    // ── Selection actions ────────────────────────────────────────────────────

    #[LiveAction]
    public function selectCourse(#[LiveArg] string $id): void
    {
        $this->courseId = $id;
        $this->groupId  = '';
        $this->loadDetail();
    }

    #[LiveAction]
    public function selectGroup(#[LiveArg] string $id): void
    {
        $this->groupId = $id;
        $this->loadDetail();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->courseId = $this->groupId = '';
        $this->errors = [];
    }

    private function loadDetail(): void
    {
        $this->errors = [];
        $this->confirmingDelete = false;
        $this->newTeacherId = $this->newTeacherSubject = '';
        $selected = $this->getSelected();
        if ($selected === null) {
            $this->editName = $this->editDetails = '';

            return;
        }

        $entity = $selected['entity'];
        $this->editName    = $entity->getName();
        $this->editDetails = $entity->getDetails() ?? '';
    }

    // ── Add actions ──────────────────────────────────────────────────────────

    #[LiveAction]
    public function addCourse(): void
    {
        $centre = $this->requireWritableCentre();
        $year   = $centre->getActiveAcademicYear();
        $name   = trim($this->addCourseName);
        if ($year === null || $name === '') {
            return;
        }

        $course = (new Course())
            ->setName($name)
            ->setAcademicYear($year);

        $this->em->persist($course);
        $this->em->flush();

        $this->addCourseName = '';
        $this->selectCourse($course->getId()->toRfc4122());
    }

    #[LiveAction]
    public function addGroup(): void
    {
        $this->requireWritableCentre();
        $course = $this->getSelectedCourse();
        $name   = trim($this->addGroupName);
        if ($course === null || $name === '') {
            return;
        }

        $group = (new Group())
            ->setName($name)
            ->setCourse($course);

        $this->em->persist($group);
        $this->em->flush();

        $this->addGroupName = '';
        $this->selectGroup($group->getId()->toRfc4122());
    }

    // ── Detail save / delete ─────────────────────────────────────────────────

    #[LiveAction]
    public function saveDetail(): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $name = trim($this->editName);
        if ($name === '') {
            $this->errors = ['name' => $this->t($selected['type'] . '.error.name_required')];

            return;
        }

        $entity  = $selected['entity'];
        $details = trim($this->editDetails);
        $entity->setName($name);
        $entity->setDetails($details !== '' ? $details : null);

        $this->em->flush();
        $this->errors = [];
        $this->addFlash('success', $this->t($selected['type'] . '.flash.saved'));
    }

    #[LiveAction]
    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    #[LiveAction]
    public function deleteSelected(): void
    {
        $this->requireWritableCentre();
        $this->confirmingDelete = false;
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $type = $selected['type'];
        try {
            $this->em->remove($selected['entity']);
            $this->em->flush();
            $this->addFlash('success', $this->t($type . '.flash.deleted'));
        } catch (\Exception) {
            $this->addFlash('error', $this->t($type . '.flash.delete_error'));

            return;
        }

        match ($type) {
            'group' => $this->groupId = '',
            default => $this->courseId = '',
        };
        $this->loadDetail();
    }

    // ── Inline staff (tutors / teachers) ─────────────────────────────────────

    /** @param string[] $ids */
    #[LiveAction]
    public function setGroupTutors(#[LiveArg] array $ids): void
    {
        $this->requireWritableCentre();
        $group = $this->getSelectedGroup();
        if ($group === null) {
            return;
        }

        $this->syncTeachers(
            $group->getTutors(),
            $ids,
            fn (Teacher $t) => $group->addTutor($t),
            fn (Teacher $t) => $group->removeTutor($t),
        );
        $this->em->flush();
        $this->addFlash('success', $this->t('group.flash.saved'));
    }

    #[LiveAction]
    public function addGroupTeacher(): void
    {
        $this->requireWritableCentre();
        $group   = $this->getSelectedGroup();
        $subject = trim($this->newTeacherSubject);
        if ($group === null) {
            return;
        }

        $teacher = Uuid::isValid($this->newTeacherId) ? $this->resolveTeacher($this->newTeacherId) : null;
        if ($teacher === null || $subject === '') {
            $this->errors = ['teachers' => $this->t('group.error.teacher_subject_required')];

            return;
        }

        if ($group->hasTeacherSubject($teacher, $subject)) {
            $this->errors = ['teachers' => $this->t('group.error.teacher_subject_duplicate')];

            return;
        }

        $group->addTeacher($teacher, $subject);
        $this->em->flush();

        $this->newTeacherId = $this->newTeacherSubject = '';
        $this->errors = [];
        $this->addFlash('success', $this->t('group.flash.saved'));
    }

    #[LiveAction]
    public function removeGroupTeacher(#[LiveArg] string $id): void
    {
        $this->requireWritableCentre();
        $group = $this->getSelectedGroup();
        if ($group === null) {
            return;
        }

        $assignment = $group->getTeacherAssignments()->findFirst(
            static fn (int $i, GroupTeacher $gt): bool => $gt->getId()->toRfc4122() === $id
        );
        if ($assignment === null) {
            return;
        }

        $group->removeTeacherAssignment($assignment);
        $this->em->flush();
        $this->addFlash('success', $this->t('group.flash.saved'));
    }

    private function resolveTeacher(string $id): ?Teacher
    {
        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $id);
    }

    /**
     * @param Collection<int, Teacher> $current
     * @param string[]                 $ids
     * @param callable(Teacher): mixed $add
     * @param callable(Teacher): mixed $remove
     */
    private function syncTeachers(Collection $current, array $ids, callable $add, callable $remove): void
    {
        $desired = [];
        foreach ($ids as $id) {
            if (Uuid::isValid($id) && ($teacher = $this->resolveTeacher($id)) !== null) {
                $desired[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        foreach ($current as $teacher) {
            if (!isset($desired[$teacher->getId()->toRfc4122()])) {
                $remove($teacher);
            }
        }

        foreach ($desired as $key => $teacher) {
            if (!$current->exists(fn (int $i, Teacher $t) => $t->getId()->toRfc4122() === $key)) {
                $add($teacher);
            }
        }
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
}
