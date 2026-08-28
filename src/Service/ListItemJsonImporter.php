<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\Tag;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Repository\SpecificProfileRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces a centre's whole list-item tree (Responsabilidades › Listas) from a previously
 * exported JSON payload (see ListItemJsonExporter). This is a full replace, not a merge: every
 * existing list item and tag of the centre is deleted first — guarded up front by the same
 * "in use" check that already protects deleting a single item
 * (ListItemTreeComponent::deleteSelected()), so a list item currently backing a profile or a
 * teacher assignment blocks the whole import instead of failing halfway through.
 */
class ListItemJsonImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListItemRepository $items,
        private readonly TagRepository $tags,
        private readonly SpecificProfileRepository $profiles,
        private readonly SpecificProfileAssignmentRepository $assignments,
    ) {}

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array{items: int, tags: int}
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

        $tagCache = [];
        $counts   = ['items' => 0, 'tags' => 0];

        $this->em->getConnection()->transactional(function () use ($existing, $centre, $items, &$tagCache, &$counts): void {
            foreach ($existing as $item) {
                $this->em->remove($item);
            }
            foreach ($this->tags->findByCentre($centre) as $tag) {
                $this->em->remove($tag);
            }
            $this->em->flush();

            $this->createLevel($items, $centre, null, $tagCache, $counts);
            $this->em->flush();
        });

        return $counts;
    }

    private function validateLevel(mixed $items): void
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException('Expected an array of list items.');
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
            $tags = $node['tags'] ?? null;
            if (!is_array($tags) || array_filter($tags, static fn (mixed $t): bool => !is_string($t)) !== []) {
                throw new \InvalidArgumentException('"tags" must be an array of strings.');
            }
            $this->validateLevel($node['children'] ?? null);
        }
    }

    /**
     * @param array<mixed, mixed>           $nodes
     * @param array<string, Tag>            $tagCache
     * @param array{items: int, tags: int}  $counts
     */
    private function createLevel(array $nodes, EducationalCentre $centre, ?ListItem $parent, array &$tagCache, array &$counts): void
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

            $tags = is_array($node['tags'] ?? null) ? $node['tags'] : [];
            foreach ($tags as $tagName) {
                if (is_string($tagName)) {
                    $item->addTag($this->resolveTag($centre, $tagName, $tagCache, $counts));
                }
            }

            $this->em->persist($item);
            ++$counts['items'];

            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $this->createLevel($children, $centre, $item, $tagCache, $counts);
        }
    }

    /**
     * @param array<string, Tag>           $tagCache
     * @param array{items: int, tags: int} $counts
     */
    private function resolveTag(EducationalCentre $centre, string $name, array &$tagCache, array &$counts): Tag
    {
        $name = trim($name);
        $key  = mb_strtolower($name);

        if (isset($tagCache[$key])) {
            return $tagCache[$key];
        }

        $tag = (new Tag())->setEducationalCentre($centre)->setName($name);
        $this->em->persist($tag);
        $tagCache[$key] = $tag;
        ++$counts['tags'];

        return $tag;
    }
}
