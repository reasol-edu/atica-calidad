<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Repository\ListItemRepository;

/**
 * Serialises a centre's list-item tree (Responsabilidades › Listas) to a plain array, ready for
 * json_encode(): each node with its own tags (never inherited ones — those are recomputed from
 * the hierarchy on import) and its children, nested.
 */
class ListItemJsonExporter
{
    public function __construct(
        private readonly ListItemRepository $items,
    ) {}

    /** @return array{type: string, items: array<int, mixed>} */
    public function export(EducationalCentre $centre): array
    {
        $byParent = [];
        foreach ($this->items->findAllByCentre($centre) as $item) {
            $key              = $item->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $item;
        }

        return [
            'type'  => 'list_items',
            'items' => $this->serializeLevel('', $byParent),
        ];
    }

    /**
     * @param array<string, ListItem[]> $byParent
     *
     * @return array<int, mixed>
     */
    private function serializeLevel(string $parentKey, array $byParent): array
    {
        $nodes = [];
        foreach ($byParent[$parentKey] ?? [] as $item) {
            $nodes[] = [
                'name'     => $item->getName(),
                'active'   => $item->isActive(),
                'tags'     => array_map(static fn ($tag): string => $tag->getName(), $item->getTags()->toArray()),
                'children' => $this->serializeLevel($item->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }
}
