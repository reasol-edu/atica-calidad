<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Repository\ActivityCategoryRepository;
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
 * Breadcrumb drill-down editor for a centre's activity-category tree — same shape as
 * ListItemTreeComponent/DocumentSectionTreeComponent's mobile fallback (one level of categories
 * visible at a time, add/rename/delete, up/down reordering), but without a desktop drag-and-drop
 * view: unlike sections, a category tree here is expected to stay shallow, so the extra
 * SortableJS-backed editor isn't worth the added complexity. Categories carry no profile
 * restrictions of their own (see ActivityCategory) — there's nothing to gate here beyond
 * EducationalCentreVoter::RESPONSIBILITIES itself.
 */
#[AsLiveComponent]
class ActivityCategoryTreeComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** '' means the root level. */
    #[LiveProp(writable: true)]
    public string $currentParentId = '';

    #[LiveProp(writable: true)]
    public string $addValue = '';

    #[LiveProp(writable: true)]
    public string $renamingId = '';

    #[LiveProp(writable: true)]
    public string $renameValue = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteId = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ActivityCategoryRepository $categories,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $this->centre = $centre;
    }

    public function getCurrentParent(): ?ActivityCategory
    {
        if ($this->currentParentId === '') {
            return null;
        }

        return $this->categories->findByIdAndCentre($this->currentParentId, $this->centre);
    }

    /** @return ActivityCategory[] */
    public function getVisibleCategories(): array
    {
        $parent = $this->getCurrentParent();

        return $parent === null
            ? $this->categories->findRootsByCentre($this->centre)
            : $this->categories->findChildrenByParent($parent);
    }

    /** @return ActivityCategory[] root-first path of ancestors down to (and including) the current parent */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentParent(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    #[LiveAction]
    public function openLevel(#[LiveArg] string $id): void
    {
        $this->currentParentId = $id;
        $this->errors          = [];
    }

    #[LiveAction]
    public function addCategory(): void
    {
        $name = trim($this->addValue);
        if ($name === '') {
            $this->errors = ['add' => $this->t('activity_category.error.name_required')];

            return;
        }

        $parent = $this->getCurrentParent();
        $category = (new ActivityCategory())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($parent === null ? $this->categories->nextRootPosition($this->centre) : $this->categories->nextChildPosition($parent));
        $category->setParent($parent);

        $this->em->persist($category);
        $this->em->flush();

        $this->addValue = '';
        $this->errors   = [];
    }

    #[LiveAction]
    public function startRename(#[LiveArg] string $id): void
    {
        $category = $this->categories->findByIdAndCentre($id, $this->centre);
        if ($category === null) {
            return;
        }

        $this->renamingId  = $id;
        $this->renameValue = $category->getName();
        $this->errors      = [];
    }

    #[LiveAction]
    public function cancelRename(): void
    {
        $this->renamingId = '';
    }

    #[LiveAction]
    public function saveRename(): void
    {
        $category = $this->categories->findByIdAndCentre($this->renamingId, $this->centre);
        if ($category === null) {
            return;
        }

        $name = trim($this->renameValue);
        if ($name === '') {
            $this->errors = ['rename' => $this->t('activity_category.error.name_required')];

            return;
        }

        $category->setName($name);
        $this->em->flush();

        $this->renamingId = '';
        $this->errors     = [];
    }

    #[LiveAction]
    public function askDelete(#[LiveArg] string $id): void
    {
        $this->confirmingDeleteId = $id;
        $this->errors             = [];
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = '';
        $this->errors             = [];
    }

    #[LiveAction]
    public function deleteCategory(#[LiveArg] string $id): void
    {
        $category = $this->categories->findByIdAndCentre($id, $this->centre);
        if ($category === null) {
            $this->confirmingDeleteId = '';

            return;
        }

        if (!$category->isLeaf()) {
            $this->errors = ['delete' => $this->t('activity_category.error.delete_has_children')];

            return;
        }

        if (!$category->getActivities()->isEmpty()) {
            $this->errors = ['delete' => $this->t('activity_category.error.delete_has_activities')];

            return;
        }

        $this->confirmingDeleteId = '';
        $parent                   = $category->getParent();

        $this->em->remove($category);
        $this->em->flush();

        if ($this->currentParentId === $id) {
            $this->currentParentId = $parent?->getId()->toRfc4122() ?? '';
        }
        $this->errors = [];
        $this->flashSuccess($this->t('activity_category.flash.deleted'));
    }

    #[LiveAction]
    public function moveUp(#[LiveArg] string $id): void
    {
        $this->reorder($id, -1);
    }

    #[LiveAction]
    public function moveDown(#[LiveArg] string $id): void
    {
        $this->reorder($id, 1);
    }

    private function reorder(string $id, int $direction): void
    {
        $target = $this->categories->findByIdAndCentre($id, $this->centre);
        if ($target === null) {
            return;
        }

        $parent   = $target->getParent();
        $siblings = array_values($parent === null
            ? $this->categories->findRootsByCentre($this->centre)
            : $this->categories->findChildrenByParent($parent));

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
