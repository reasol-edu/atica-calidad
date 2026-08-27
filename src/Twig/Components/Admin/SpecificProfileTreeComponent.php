<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\SpecificProfileRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
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
 * Inline editor for a centre's custom "specific profile" hierarchy: root
 * profiles (e.g. "Docente") with up to one level of children (e.g. "Docente
 * ESO/Bach", "Docente FP"). Only profiles without children ("leaves" — a root
 * with zero children counts as one) can have teachers assigned directly; a
 * profile with children is a purely organisational category.
 */
#[AsLiveComponent]
class SpecificProfileTreeComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $rootId = '';

    #[LiveProp(writable: true)]
    public string $childId = '';

    /** Inline "add" inputs, one per column. */
    #[LiveProp(writable: true)]
    public string $addRootName = '';

    #[LiveProp(writable: true)]
    public string $addChildName = '';

    /** Detail-panel field for the selected profile. */
    #[LiveProp(writable: true)]
    public string $editName = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    /** Inline two-step confirmation for deleting the selected profile. */
    #[LiveProp(writable: true)]
    public bool $confirmingDelete = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly SpecificProfileRepository $profiles,
        private readonly TeacherRepository $teachers,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);
        $this->centre = $centre;
    }

    /**
     * Unlike OfferTreeComponent/TeacherSubjectsComponent (year-scoped data,
     * gated by "not viewing a non-active year"), specific profiles are
     * permanent per centre. The active year is only needed to scope the
     * teacher-search widget's candidate pool.
     */
    public function canWrite(): bool
    {
        return $this->centre->getActiveAcademicYear() !== null;
    }

    // ── Column data ──────────────────────────────────────────────────────────

    /** @return SpecificProfile[] */
    public function getRootProfiles(): array
    {
        return $this->profiles->findRootsByCentre($this->centre);
    }

    /** @return array<string, int> */
    public function getRootTeacherCounts(): array
    {
        return $this->profiles->findTeacherCountsByProfiles($this->getRootProfiles());
    }

    /** @return SpecificProfile[] */
    public function getChildProfiles(): array
    {
        $root = $this->getSelectedRoot();

        return $root === null ? [] : $this->profiles->findChildrenByParent($root);
    }

    /** @return array<string, int> */
    public function getChildTeacherCounts(): array
    {
        return $this->profiles->findTeacherCountsByProfiles($this->getChildProfiles());
    }

    // ── Selected entities ────────────────────────────────────────────────────

    public function getSelectedRoot(): ?SpecificProfile
    {
        if ($this->rootId === '') {
            return null;
        }

        $root = $this->profiles->findByIdAndCentre($this->rootId, $this->centre);

        // Defense in depth: rootId is client-writable — never trust it to
        // actually be a root profile.
        return ($root !== null && $root->isRoot()) ? $root : null;
    }

    public function getSelectedChild(): ?SpecificProfile
    {
        $root = $this->getSelectedRoot();
        if ($root === null || $this->childId === '') {
            return null;
        }

        $child = $this->profiles->findByIdAndCentre($this->childId, $this->centre);

        // Defense in depth: childId must actually belong to the currently selected root.
        return ($child !== null && $child->getParent() === $root) ? $child : null;
    }

    public function getSelected(): ?SpecificProfile
    {
        return $this->getSelectedChild() ?? $this->getSelectedRoot();
    }

    public function canAssignTeachers(): bool
    {
        return $this->getSelected()?->isLeaf() ?? false;
    }

    /** @return Teacher[] */
    public function getSelectedTeachers(): array
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return [];
        }

        $list = $selected->getTeachers()->toArray();
        usort($list, static fn (Teacher $a, Teacher $b) => $a->getName()->getLastName() <=> $b->getName()->getLastName());

        return $list;
    }

    // ── Selection actions ────────────────────────────────────────────────────

    #[LiveAction]
    public function selectRoot(#[LiveArg] string $id): void
    {
        $this->rootId  = $id;
        $this->childId = '';
        $this->loadDetail();
    }

    #[LiveAction]
    public function selectChild(#[LiveArg] string $id): void
    {
        $this->childId = $id;
        $this->loadDetail();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->rootId = $this->childId = '';
        $this->errors = [];
    }

    private function loadDetail(): void
    {
        $this->errors = [];
        $this->confirmingDelete = false;
        $selected = $this->getSelected();
        $this->editName = $selected?->getName() ?? '';
    }

    // ── Add actions ──────────────────────────────────────────────────────────

    #[LiveAction]
    public function addRoot(): void
    {
        $this->requireWritableCentre();
        $name = trim($this->addRootName);
        if ($name === '') {
            $this->errors = ['add_root' => $this->t('responsibilities.specific_profiles.error.name_required')];

            return;
        }

        $profile = (new SpecificProfile())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($this->profiles->nextRootPosition($this->centre));

        $this->em->persist($profile);
        $this->em->flush();

        $this->addRootName = '';
        $this->selectRoot($profile->getId()->toRfc4122());
    }

    #[LiveAction]
    public function addChild(): void
    {
        $this->requireWritableCentre();
        $root = $this->getSelectedRoot();
        if ($root === null) {
            return;
        }

        if (!$root->getTeachers()->isEmpty()) {
            $this->errors = ['add_child' => $this->t('responsibilities.specific_profiles.error.parent_has_teachers')];

            return;
        }

        $name = trim($this->addChildName);
        if ($name === '') {
            $this->errors = ['add_child' => $this->t('responsibilities.specific_profiles.error.name_required')];

            return;
        }

        $child = (new SpecificProfile())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($this->profiles->nextChildPosition($root));
        $child->setParent($root);

        $this->em->persist($child);
        $this->em->flush();

        $this->addChildName = '';
        $this->selectChild($child->getId()->toRfc4122());
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
            $this->errors = ['name' => $this->t('responsibilities.specific_profiles.error.name_required')];

            return;
        }

        $selected->setName($name);
        $this->em->flush();

        $this->errors = [];
        $this->flashSuccess($this->t('responsibilities.specific_profiles.flash.saved'));
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

        $wasRoot = $selected->isRoot();

        try {
            $this->em->remove($selected);
            $this->em->flush();
            $this->flashSuccess($this->t('responsibilities.specific_profiles.flash.deleted'));
        } catch (\Exception) {
            $this->flashError($this->t('responsibilities.specific_profiles.flash.delete_error'));

            return;
        }

        if ($wasRoot) {
            $this->rootId = $this->childId = '';
        } else {
            $this->childId = '';
        }
        $this->loadDetail();
    }

    // ── Reordering ───────────────────────────────────────────────────────────

    #[LiveAction]
    public function moveUp(#[LiveArg] string $id): void
    {
        $this->move($id, -1);
    }

    #[LiveAction]
    public function moveDown(#[LiveArg] string $id): void
    {
        $this->move($id, 1);
    }

    private function move(string $id, int $direction): void
    {
        $this->requireWritableCentre();
        $target = $this->profiles->findByIdAndCentre($id, $this->centre);
        if ($target === null) {
            return;
        }

        $parent   = $target->getParent();
        $siblings = array_values($parent === null
            ? $this->profiles->findRootsByCentre($this->centre)
            : $this->profiles->findChildrenByParent($parent));

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling === $target) {
                $index = $i;

                break;
            }
        }

        $swapWith = $index === null ? null : ($siblings[$index + $direction] ?? null);
        if ($swapWith === null) {
            return;
        }

        $targetPosition   = $target->getPosition();
        $target->setPosition($swapWith->getPosition());
        $swapWith->setPosition($targetPosition);

        $this->em->flush();
    }

    #[LiveAction]
    public function sortRootsAlphabetically(): void
    {
        $this->requireWritableCentre();
        $this->sortByName($this->profiles->findRootsByCentre($this->centre));
    }

    #[LiveAction]
    public function sortChildrenAlphabetically(): void
    {
        $this->requireWritableCentre();
        $root = $this->getSelectedRoot();
        if ($root === null) {
            return;
        }

        $this->sortByName($this->profiles->findChildrenByParent($root));
    }

    /** @param SpecificProfile[] $siblings */
    private function sortByName(array $siblings): void
    {
        usort($siblings, static fn (SpecificProfile $a, SpecificProfile $b) => strcmp($a->getName(), $b->getName()));

        foreach ($siblings as $position => $sibling) {
            $sibling->setPosition($position);
        }

        $this->em->flush();
    }

    // ── Teacher assignment (leaf profiles only) ─────────────────────────────

    #[LiveAction]
    public function assignTeacher(#[LiveArg] string $teacherId): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        if ($selected === null || !$selected->isLeaf()) {
            $this->errors = ['assign' => $this->t('responsibilities.specific_profiles.error.not_assignable')];

            return;
        }

        $teacher = $this->resolveTeacher($teacherId);
        if ($teacher === null || $selected->getTeachers()->contains($teacher)) {
            return;
        }

        $selected->addTeacher($teacher);
        $this->em->flush();
        $this->flashSuccess($this->t('responsibilities.specific_profiles.flash.teacher_assigned'));
    }

    #[LiveAction]
    public function removeTeacher(#[LiveArg] string $teacherId): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $teacher = $this->resolveTeacher($teacherId);
        if ($teacher === null) {
            return;
        }

        $selected->removeTeacher($teacher);
        $this->em->flush();
        $this->flashSuccess($this->t('responsibilities.specific_profiles.flash.teacher_removed'));
    }

    private function resolveTeacher(string $id): ?Teacher
    {
        $year = $this->centre->getActiveAcademicYear();

        return $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $id);
    }

    private function requireWritableCentre(): EducationalCentre
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $this->centre);
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
     * LiveAction responses only re-render this component's fragment, not the
     * layout, so a plain addFlash() never reaches the page until the next
     * full navigation. Dispatch a browser event instead so the layout's JS
     * can render the flash immediately.
     */
    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }

    private function flashError(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'error', 'message' => $message]);
    }
}
