<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces a centre's whole list-item tree (Responsabilidades › Listas) from a previously
 * exported JSON payload (see ListItemJsonExporter). This is a full replace, not a merge: every
 * existing list item of the centre is deleted first — guarded up front by the same
 * "in use" check that already protects deleting a single item
 * (ListItemTreeComponent::deleteSelected()), so a list item currently backing a profile or a
 * teacher assignment blocks the whole import instead of failing halfway through.
 *
 * Files exported before tags were removed carry a "tags" array in each node: it is ignored.
 */
class ListItemJsonImporter
{
    /**
     * Nesting deeper than this is refused outright: no real tree comes close, and it keeps a
     * crafted file from walking the importer down hundreds of levels.
     */
    private const int MAX_DEPTH = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListItemRepository $items,
        private readonly SpecificProfileRepository $profiles,
        private readonly SpecificProfileAssignmentRepository $assignments,
    ) {}

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array{items: int}
     *
     * @throws \InvalidArgumentException malformed JSON payload
     * @throws \DomainException          an existing list item is in use and the import was refused
     */
    public function import(array $data, EducationalCentre $centre): array
    {
        $items = $data['items'] ?? null;
        if (($data['type'] ?? null) !== 'list_items' || !is_array($items)) {
            throw new \InvalidArgumentException('Not a valid list-items export.');
        }
        $this->validateLevel($items);

        $existing = $this->items->findAllByCentre($centre);
        foreach ($existing as $item) {
            if ($this->profiles->isListItemInUse($item) || $this->assignments->isListItemAssigned($item)) {
                throw new \DomainException('in_use');
            }
        }

        $counts = ['items' => 0];

        $this->em->getConnection()->transactional(function () use ($existing, $centre, $items, &$counts): void {
            foreach ($existing as $item) {
                $this->em->remove($item);
            }
            $this->em->flush();

            $this->createLevel($items, $centre, null, $counts);
            $this->em->flush();
        });

        return $counts;
    }

    private function validateLevel(mixed $items, int $depth = 1): void
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException('Expected an array of list items.');
        }
        if ($items !== [] && $depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(\sprintf('The tree is nested more than %d levels deep.', self::MAX_DEPTH));
        }

        foreach ($items as $node) {
            if (!is_array($node)) {
                throw new \InvalidArgumentException('Each list item must be an object.');
            }
            if (!is_string($node['name'] ?? null) || trim($node['name']) === '') {
                throw new \InvalidArgumentException('Each list item needs a non-empty "name".');
            }
            if (!is_bool($node['active'] ?? null)) {
                throw new \InvalidArgumentException('Each list item needs a boolean "active".');
            }
            $this->validateLevel($node['children'] ?? null, $depth + 1);
        }
    }

    /**
     * @param array<mixed, mixed> $nodes
     * @param array{items: int}   $counts
     */
    private function createLevel(array $nodes, EducationalCentre $centre, ?ListItem $parent, array &$counts): void
    {
        foreach (array_values($nodes) as $position => $node) {
            $name = is_array($node) ? $node['name'] ?? null : null;
            if (!is_string($name)) {
                continue;
            }

            $item = (new ListItem())
                ->setEducationalCentre($centre)
                ->setName(trim($name))
                ->setActive($node['active'] === true)
                ->setPosition($position);
            $item->setParent($parent);

            $this->em->persist($item);
            ++$counts['items'];

            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $this->createLevel($children, $centre, $item, $counts);
        }
    }
}
