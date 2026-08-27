<?php

declare(strict_types=1);

namespace App\Twig\Components\Admin;

use App\Entity\DocumentSection;
use App\Entity\DocumentSectionProfile;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Model\ProfileAssignmentRow;
use App\Repository\DocumentSectionRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
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
 * Visual editor for a centre's document section tree: a full nested drag-and-drop view for
 * desktop, and a breadcrumb drill-down fallback (no drag, up/down reordering) for small screens —
 * same state and LiveActions, two renderings picked by CSS breakpoint (see the component's
 * template).
 */
#[AsLiveComponent]
class DocumentSectionTreeComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    /**
     * Marks the root "add" box as open in `$addingParentId` — distinct from '' (nothing open) and
     * from a real section id. Never passed as the actual parent id to addSection(): a root section
     * is created with parentId === ''.
     */
    private const ROOT_SENTINEL = '@root';

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $renamingId = '';

    #[LiveProp(writable: true)]
    public string $renameValue = '';

    /** '' = no add box open, self::ROOT_SENTINEL = the root add box, otherwise a section id. */
    #[LiveProp(writable: true)]
    public string $addingParentId = '';

    #[LiveProp(writable: true)]
    public string $addValue = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteId = '';

    #[LiveProp(writable: true)]
    public string $profilePanelId = '';

    /** '' means the root level. Drives the mobile breadcrumb drill-down only. */
    #[LiveProp(writable: true)]
    public string $currentParentId = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly DocumentSectionRepository $sections,
        private readonly SpecificProfileRepository $profiles,
        private readonly ListItemRepository $listItems,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $this->centre = $centre;
    }

    public function getRootSentinel(): string
    {
        return self::ROOT_SENTINEL;
    }

    // ── Desktop: full nested tree ───────────────────────────────────────────

    /**
     * The whole centre's tree, nested, for the desktop drag-and-drop editor.
     *
     * @return array<int, array{section: DocumentSection, children: array<mixed>}>
     */
    public function getTree(): array
    {
        $byParent = [];
        foreach ($this->sections->findAllByCentre($this->centre) as $section) {
            $key              = $section->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $section;
        }

        return $this->buildNodes('', $byParent);
    }

    /**
     * @param array<string, DocumentSection[]> $byParent
     *
     * @return array<int, array{section: DocumentSection, children: array<mixed>}>
     */
    private function buildNodes(string $parentKey, array $byParent): array
    {
        $nodes = [];
        foreach ($byParent[$parentKey] ?? [] as $section) {
            $nodes[] = [
                'section'  => $section,
                'children' => $this->buildNodes($section->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }

    // ── Mobile: breadcrumb drill-down ───────────────────────────────────────

    public function getCurrentParent(): ?DocumentSection
    {
        if ($this->currentParentId === '') {
            return null;
        }

        return $this->sections->findByIdAndCentre($this->currentParentId, $this->centre);
    }

    /** @return DocumentSection[] */
    public function getVisibleSections(): array
    {
        $parent = $this->getCurrentParent();

        return $parent === null
            ? $this->sections->findRootsByCentre($this->centre)
            : $this->sections->findChildrenByParent($parent);
    }

    /** @return DocumentSection[] root-first path of ancestors down to (and including) the current parent */
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

    // ── Profiles ─────────────────────────────────────────────────────────────

    /** @return ProfileAssignmentRow[] */
    public function getAvailableProfileRows(): array
    {
        return $this->rowBuilder->buildActiveRows($this->centre);
    }

    #[LiveAction]
    public function toggleProfilePanel(#[LiveArg] string $id): void
    {
        $this->profilePanelId = $this->profilePanelId === $id ? '' : $id;
    }

    #[LiveAction]
    public function toggleProfileRestriction(#[LiveArg] string $id, #[LiveArg] string $rowKey): void
    {
        $section = $this->sections->findByIdAndCentre($id, $this->centre);
        if ($section === null) {
            return;
        }

        [$profile, $listItem] = $this->resolveRowKey($rowKey);
        if ($profile === null) {
            return;
        }

        if ($section->hasProfileRestriction($profile, $listItem)) {
            $restriction = $section->getProfileRestrictions()->findFirst(
                static fn (int $i, DocumentSectionProfile $r): bool =>
                    $r->getSpecificProfile() === $profile && $r->getListItem() === $listItem
            );
            if ($restriction !== null) {
                $section->removeProfileRestriction($restriction);
            }
        } else {
            $section->addProfileRestriction($profile, $listItem);
        }

        $this->em->flush();
    }

    /** @return array{0: ?SpecificProfile, 1: ?ListItem} */
    private function resolveRowKey(string $rowKey): array
    {
        [$profileId, $listItemId] = array_pad(explode(':', $rowKey, 2), 2, null);
        if ($profileId === null) {
            return [null, null];
        }

        $profile = $this->profiles->findByIdAndCentre($profileId, $this->centre);
        if ($profile === null) {
            return [null, null];
        }

        $listItem = $listItemId === null ? null : $this->listItems->findByIdAndCentre($listItemId, $this->centre);

        return [$profile, $listItem];
    }

    // ── Add / rename / delete ────────────────────────────────────────────────

    #[LiveAction]
    public function toggleAdd(#[LiveArg] string $parentId): void
    {
        $this->addingParentId = $this->addingParentId === $parentId ? '' : $parentId;
        $this->addValue       = '';
        $this->errors         = [];
    }

    #[LiveAction]
    public function addSection(#[LiveArg] string $parentId): void
    {
        $name = trim($this->addValue);
        if ($name === '') {
            $this->errors = ['add' => $this->t('document_section.error.name_required')];

            return;
        }

        $parent = $parentId === '' ? null : $this->sections->findByIdAndCentre($parentId, $this->centre);
        if ($parentId !== '' && $parent === null) {
            return;
        }

        $section = (new DocumentSection())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setPosition($parent === null ? $this->sections->nextRootPosition($this->centre) : $this->sections->nextChildPosition($parent));
        $section->setParent($parent);

        $this->em->persist($section);
        $this->em->flush();

        $this->addValue       = '';
        $this->addingParentId = '';
        $this->errors         = [];
    }

    #[LiveAction]
    public function startRename(#[LiveArg] string $id): void
    {
        $section = $this->sections->findByIdAndCentre($id, $this->centre);
        if ($section === null) {
            return;
        }

        $this->renamingId  = $id;
        $this->renameValue = $section->getName();
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
        $section = $this->sections->findByIdAndCentre($this->renamingId, $this->centre);
        if ($section === null) {
            return;
        }

        $name = trim($this->renameValue);
        if ($name === '') {
            $this->errors = ['rename' => $this->t('document_section.error.name_required')];

            return;
        }

        $section->setName($name);
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
    public function deleteSection(#[LiveArg] string $id): void
    {
        $section = $this->sections->findByIdAndCentre($id, $this->centre);
        if ($section === null) {
            $this->confirmingDeleteId = '';

            return;
        }

        if (!$section->isLeaf()) {
            $this->errors = ['delete' => $this->t('document_section.error.delete_has_children')];

            return;
        }

        $this->confirmingDeleteId = '';
        $parent                   = $section->getParent();

        $this->em->remove($section);
        $this->em->flush();

        if ($this->currentParentId === $id) {
            $this->currentParentId = $parent?->getId()->toRfc4122() ?? '';
        }
        $this->errors = [];
        $this->flashSuccess($this->t('document_section.flash.deleted'));
    }

    // ── Reordering ───────────────────────────────────────────────────────────

    /**
     * Mobile fallback: swap position with the previous/next sibling.
     */
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
        $target = $this->sections->findByIdAndCentre($id, $this->centre);
        if ($target === null) {
            return;
        }

        $parent   = $target->getParent();
        $siblings = array_values($parent === null
            ? $this->sections->findRootsByCentre($this->centre)
            : $this->sections->findChildrenByParent($parent));

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

    /**
     * Desktop drag-and-drop: called once per drop with the id of the dragged section, the id of
     * the list it was dropped into ('' for the root list) and the full ordered list of section ids
     * now in that destination list (including the dragged one), read straight from the DOM by the
     * SortableJS wrapper. Handles same-level reordering and reparenting in one call.
     *
     * @param string[] $orderedIds
     */
    #[LiveAction]
    public function moveSection(#[LiveArg] string $id, #[LiveArg] string $newParentId, #[LiveArg] array $orderedIds): void
    {
        $target = $this->sections->findByIdAndCentre($id, $this->centre);
        if ($target === null) {
            return;
        }

        $newParent = $newParentId === '' ? null : $this->sections->findByIdAndCentre($newParentId, $this->centre);
        if ($newParentId !== '' && $newParent === null) {
            return;
        }

        $oldParent   = $target->getParent();
        $oldParentId = $oldParent?->getId()->toRfc4122() ?? '';

        try {
            $target->setParent($newParent);
        } catch (\LogicException) {
            return;
        }

        foreach ($orderedIds as $position => $siblingId) {
            $sibling = $siblingId === $id ? $target : $this->sections->findByIdAndCentre((string) $siblingId, $this->centre);
            $sibling?->setPosition($position);
        }

        if ($oldParentId !== $newParentId) {
            $remaining = $oldParent === null
                ? $this->sections->findRootsByCentre($this->centre)
                : $this->sections->findChildrenByParent($oldParent);

            $position = 0;
            foreach ($remaining as $sibling) {
                if ($sibling === $target) {
                    continue;
                }
                $sibling->setPosition($position);
                ++$position;
            }
        }

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
