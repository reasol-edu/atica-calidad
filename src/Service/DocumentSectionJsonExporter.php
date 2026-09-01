<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Repository\DocumentSectionRepository;

/**
 * Serialises a centre's document-section tree (Document Tree) to a plain array, ready for
 * json_encode(): each node with its associated profiles/subprofiles referenced by name (not by
 * id, which would be meaningless when reimporting later or into a different centre) and its
 * children, nested.
 */
class DocumentSectionJsonExporter
{
    public function __construct(
        private readonly DocumentSectionRepository $sections,
    ) {}

    /** @return array{type: string, sections: array<int, mixed>} */
    public function export(EducationalCentre $centre): array
    {
        $byParent = [];
        foreach ($this->sections->findAllByCentre($centre) as $section) {
            $key              = $section->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $section;
        }

        return [
            'type'     => 'document_sections',
            'sections' => $this->serializeLevel('', $byParent),
        ];
    }

    /**
     * @param array<string, DocumentSection[]> $byParent
     *
     * @return array<int, mixed>
     */
    private function serializeLevel(string $parentKey, array $byParent): array
    {
        $nodes = [];
        foreach ($byParent[$parentKey] ?? [] as $section) {
            $profiles = [];
            foreach ($section->getProfileRestrictions() as $restriction) {
                $profiles[] = [
                    'profile'      => $restriction->getSpecificProfile()->getName(),
                    'listItemPath' => $this->pathFor($restriction->getListItem()),
                ];
            }

            $nodes[] = [
                'name'     => $section->getName(),
                'profiles' => $profiles,
                'children' => $this->serializeLevel($section->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }

    /** @return array<int, string>|null root-first names, or null when there is no list item (a direct profile) */
    private function pathFor(?ListItem $listItem): ?array
    {
        if ($listItem === null) {
            return null;
        }

        $path = [];
        for ($item = $listItem; $item !== null; $item = $item->getParent()) {
            array_unshift($path, $item->getName());
        }

        return $path;
    }
}
