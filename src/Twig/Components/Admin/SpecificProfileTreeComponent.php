<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
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
 * Inline editor for a centre's flat list of "specific profiles" (e.g.
 * "Tutor/a"). Each profile is optionally associated with a list element
 * (see ListItem): when it is, every leaf descendant of that element is a
 * "virtual subperfil" (e.g. "Tutor/a 1º ESO-A") with its own independent
 * teacher assignments, browsed and edited here; when it isn't, teachers are
 * assigned to the profile directly. List items themselves are managed on
 * the separate "Listas" screen — this component only picks among them.
 */
#[AsLiveComponent]
class SpecificProfileTreeComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $selectedId = '';

    #[LiveProp(writable: true)]
    public string $addName = '';

    #[LiveProp(writable: true)]
    public string $editName = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    #[LiveProp(writable: true)]
    public bool $confirmingDelete = false;

    /** Picker for choosing which list element a profile is associated with. */
    #[LiveProp(writable: true)]
    public bool $pickerActive = false;

    #[LiveProp(writable: true)]
    public string $pickerParentId = '';

    /** Which leaf ("subperfil") of the associated list element is currently being edited. */
    #[LiveProp(writable: true)]
    public string $selectedLeafId = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly SpecificProfileRepository $profiles,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly ListItemRepository $listItems,
        private readonly TeacherRepository $teachers,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
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

    // ── Profile list ─────────────────────────────────────────────────────────

    /** @return SpecificProfile[] */
    public function getProfiles(): array
    {
        return $this->profiles->findByCentre($this->centre);
    }

    /** @return array<string, int> */
    public function getProfileTeacherCounts(): array
    {
        return $this->assignments->findAssignedTeacherCountsByProfiles($this->getProfiles());
    }

    public function getSelected(): ?SpecificProfile
    {
        if ($this->selectedId === '') {
            return null;
        }

        return $this->profiles->findByIdAndCentre($this->selectedId, $this->centre);
    }

    #[LiveAction]
    public function selectProfile(#[LiveArg] string $id): void
    {
        $this->selectedId = $id;
        $this->loadDetail();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedId = '';
        $this->errors     = [];
    }

    private function loadDetail(): void
    {
        $this->errors         = [];
        $this->confirmingDelete = false;
        $this->pickerActive     = false;
        $this->selectedLeafId   = '';
        $selected                = $this->getSelected();
        $this->editName          = $selected?->getName() ?? '';
    }

    #[LiveAction]
    public function addProfile(): void
    {
        $this->requireWritableCentre();
        $name = trim($this->addName);
        if ($name === '') {
            $this->errors = ['add' => $this->t('responsibilities.specific_profiles.error.name_required')];

            return;
        }

        $profile = (new SpecificProfile())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($this->profiles->nextPosition($this->centre));

        $this->em->persist($profile);
        $this->em->flush();

        $this->addName = '';
        $this->selectProfile($profile->getId()->toRfc4122());
    }

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
        $selected               = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $this->em->remove($selected);
        $this->em->flush();

        $this->selectedId = '';
        $this->loadDetail();
        $this->flashSuccess($this->t('responsibilities.specific_profiles.flash.deleted'));
    }

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

        $siblings = array_values($this->profiles->findByCentre($this->centre));

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

        $targetPosition = $target->getPosition();
        $target->setPosition($swapWith->getPosition());
        $swapWith->setPosition($targetPosition);

        $this->em->flush();
    }

    #[LiveAction]
    public function sortAlphabetically(): void
    {
        $this->requireWritableCentre();
        $profiles = $this->getProfiles();
        usort($profiles, static fn (SpecificProfile $a, SpecificProfile $b) => strcmp($a->getName(), $b->getName()));

        foreach ($profiles as $position => $profile) {
            $profile->setPosition($position);
        }

        $this->em->flush();
    }

    // ── List-element picker ──────────────────────────────────────────────────

    #[LiveAction]
    public function openPicker(): void
    {
        $this->pickerActive   = true;
        $this->pickerParentId = '';
    }

    #[LiveAction]
    public function closePicker(): void
    {
        $this->pickerActive = false;
    }

    #[LiveAction]
    public function pickerNavigate(#[LiveArg] string $id): void
    {
        $this->pickerParentId = $id;
    }

    public function getPickerParent(): ?ListItem
    {
        if ($this->pickerParentId === '') {
            return null;
        }

        return $this->listItems->findByIdAndCentre($this->pickerParentId, $this->centre);
    }

    /** @return ListItem[] */
    public function getPickerVisibleItems(): array
    {
        $parent = $this->getPickerParent();

        return $parent === null
            ? $this->listItems->findRootsByCentre($this->centre)
            : $this->listItems->findChildrenByParent($parent);
    }

    /** @return ListItem[] */
    public function getPickerBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getPickerParent(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    #[LiveAction]
    public function pickListItem(#[LiveArg] string $id): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        $item     = $this->listItems->findByIdAndCentre($id, $this->centre);
        if ($selected === null || $item === null || !$item->isActive()) {
            return;
        }

        $this->applyListAssociation($selected, $item);
        $this->pickerActive   = false;
        $this->selectedLeafId = '';
    }

    #[LiveAction]
    public function clearListAssociation(): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        if ($selected === null) {
            return;
        }

        $this->applyListAssociation($selected, null);
        $this->selectedLeafId = '';
    }

    /**
     * Changing (or clearing) a profile's associated list element invalidates
     * every existing assignment — they were scoped to the previous leaves
     * (or to direct mode), which no longer apply.
     */
    private function applyListAssociation(SpecificProfile $profile, ?ListItem $item): void
    {
        foreach ($profile->getAssignments()->toArray() as $assignment) {
            $profile->removeAssignment($assignment);
        }
        $profile->setListItem($item);
        $this->em->flush();
    }

    // ── Leaves ("subperfiles") of an associated list element ────────────────

    /** @return ListItem[] */
    public function getLeaves(): array
    {
        $selected = $this->getSelected();
        $listItem = $selected?->getListItem();

        return $listItem === null ? [] : $this->listItems->findLeafDescendants($listItem);
    }

    /** @return array<string, int> */
    public function getLeafTeacherCounts(): array
    {
        $selected = $this->getSelected();

        return $selected === null ? [] : $this->assignments->findTeacherCountsByListItems($selected, $this->getLeaves());
    }

    public function getSelectedLeaf(): ?ListItem
    {
        if ($this->selectedLeafId === '') {
            return null;
        }

        foreach ($this->getLeaves() as $leaf) {
            if ($leaf->getId()->toRfc4122() === $this->selectedLeafId) {
                return $leaf;
            }
        }

        return null;
    }

    #[LiveAction]
    public function selectLeaf(#[LiveArg] string $id): void
    {
        $this->selectedLeafId = $id;
        $this->errors         = [];
    }

    #[LiveAction]
    public function clearLeafSelection(): void
    {
        $this->selectedLeafId = '';
    }

    // ── Teacher assignment ───────────────────────────────────────────────────

    /** Whether the currently relevant assignable unit (the profile itself, or the selected leaf) is ready to take assignments. */
    public function canAssignTeachers(): bool
    {
        $selected = $this->getSelected();
        if ($selected === null) {
            return false;
        }

        return $selected->isListAssociated() ? $this->getSelectedLeaf() !== null : true;
    }

    /** @return Teacher[] */
    public function getAssignedTeachers(): array
    {
        $selected = $this->getSelected();
        if ($selected === null || !$this->canAssignTeachers()) {
            return [];
        }

        return $this->assignments->findTeachersByProfileAndListItem($selected, $this->getSelectedLeaf());
    }

    #[LiveAction]
    public function assignTeacher(#[LiveArg] string $teacherId): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        if ($selected === null || !$this->canAssignTeachers()) {
            $this->errors = ['assign' => $this->t('responsibilities.specific_profiles.error.not_assignable')];

            return;
        }

        $teacher = $this->resolveTeacher($teacherId);
        if ($teacher === null) {
            return;
        }

        $selected->addAssignment($teacher, $this->getSelectedLeaf());
        $this->em->flush();
        $this->flashSuccess($this->t('responsibilities.specific_profiles.flash.teacher_assigned'));
    }

    #[LiveAction]
    public function removeTeacher(#[LiveArg] string $teacherId): void
    {
        $this->requireWritableCentre();
        $selected = $this->getSelected();
        $teacher  = $this->resolveTeacher($teacherId);
        if ($selected === null || $teacher === null) {
            return;
        }

        foreach ($selected->getAssignments() as $assignment) {
            if ($assignment->getTeacher() === $teacher && $assignment->getListItem() === $this->getSelectedLeaf()) {
                $selected->removeAssignment($assignment);
            }
        }
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
