<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\ListItem;
use App\Repository\ListItemRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * How the submissions of an activity pinned to a catalogue element are named after their leaf, and the way back: which leaf a delivered
 * document was named after. Dependent only on the list tree (its one memo is dropped by any flush), so that both ActivitySubmissionSlotBuilder (which builds the
 * slots) and DocumentTreeAccessChecker (which the slot builder itself depends on) can use it without depending on each other.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class ActivityLeafResolver implements ResetInterface
{
    /** @var array<string, array<string, ListItem>> the leaves of an element by submission name, see findLeafForDocument() */
    private array $leavesByName = [];

    public function __construct(
        private readonly ListItemRepository $listItems,
    ) {}

    /**
     * A submission's name: the list path from just under the activity's selected element down to
     * the leaf, joined with " › " (e.g. selecting "Materias" names a leaf "Ciencias › Física").
     * A leaf directly under the selected element — or the selected element itself, when a leaf was
     * picked — is just its own name. With $fullPath false (the activity's «solo el nombre del elemento»
     * setting) it is the leaf's own name whatever its depth («Física»): then two leaves with the same
     * name would be the same submission, which the form refuses (see ActivitySubmissionSlotBuilder::duplicateLeafNames()).
     */
    public function submissionName(ListItem $selected, ListItem $leaf, bool $fullPath = true): string
    {
        if (!$fullPath) {
            return $leaf->getName();
        }

        $trail = [];
        for ($node = $leaf; $node !== null && $node !== $selected; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return $trail === [] ? $leaf->getName() : implode(' › ', $trail);
    }

    /**
     * The leaf of $activity's own list item that a persisted $document was named after — matched
     * by recomputing submissionName() for every leaf and comparing against $document's own name,
     * the same naming rule the slot builder uses, just backwards. Used wherever something about
     * $document needs to know which leaf (if any) it belongs to — e.g. stamping its cycle year at
     * creation time with that leaf's own deadline override, or deciding whether it's a past
     * occurrence's submission. Deliberately light: it never touches the folder's upload-row/profile
     * resolution, which runs from a Doctrine entity listener (see DocumentActivityCycleListener)
     * where other entities in the same flush may not have their id assigned yet. Null when the
     * activity has no list item, or $document's name matches no current leaf (the list item changed
     * after it was uploaded).
     */
    public function findLeafForDocument(Activity $activity, Document $document): ?ListItem
    {
        $listItem = $activity->getListItem();
        if ($listItem === null) {
            return null;
        }

        // The access checks ask this once per document of a folder: the names of the leaves are worked out once per element and
        // naming rule, not once per document. Keyed by object, not id: this also runs from an entity listener in the middle of a flush.
        $key    = spl_object_id($listItem) . '|' . ($activity->isSubmissionNameFullPath() ? '1' : '0');
        $byName = $this->leavesByName[$key] ??= $this->namesOf($listItem, $activity->isSubmissionNameFullPath());

        return $byName[$document->getName()] ?? null;
    }

    /** Any flush may have renamed, moved or added elements: the names are worked out again. */
    public function postFlush(): void
    {
        $this->leavesByName = [];
    }

    public function reset(): void
    {
        $this->leavesByName = [];
    }

    /** @return array<string, ListItem> each leaf under $listItem by its submission name — the first one, if two share it */
    private function namesOf(ListItem $listItem, bool $fullPath): array
    {
        $byName = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $byName[$this->submissionName($listItem, $leaf, $fullPath)] ??= $leaf;
        }

        return $byName;
    }
}
