<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\DocumentSectionRepository;

/**
 * The centre's document tree sections as a flat, indented list for a select — "¿Dónde?" when
 * reporting an incident, the process of a finding. Only the sections $teacher can see: a
 * restricted section's very name may be none of their business.
 */
final class SectionChoiceBuilder
{
    public function __construct(
        private readonly DocumentSectionRepository $sections,
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    /**
     * In tree order; "indented" prefixes the label with em spaces by depth, for an <option>.
     *
     * @return list<array{id: string, label: string, indented: string, depth: int}>
     */
    public function choices(Teacher $teacher, EducationalCentre $centre): array
    {
        // One query for the whole tree, grouped by parent in memory (position order is kept).
        $byParent = [];
        foreach ($this->sections->findAllByCentre($centre) as $section) {
            $byParent[$section->getParent()?->getId()->toRfc4122() ?? ''][] = $section;
        }

        $choices = [];
        foreach ($byParent[''] ?? [] as $root) {
            $this->add($root, 0, $teacher, $byParent, $choices);
        }

        return $choices;
    }

    /**
     * @param array<string, list<DocumentSection>>                                  $byParent
     * @param list<array{id: string, label: string, indented: string, depth: int}> $choices
     */
    private function add(DocumentSection $section, int $depth, Teacher $teacher, array $byParent, array &$choices): void
    {
        if (!$this->access->canViewSection($teacher, $section)) {
            return;
        }

        $choices[] = [
            'id'       => $section->getId()->toRfc4122(),
            'label'    => $section->getName(),
            'indented' => str_repeat("\u{2003}", $depth) . $section->getName(),
            'depth'    => $depth,
        ];
        foreach ($byParent[$section->getId()->toRfc4122()] ?? [] as $child) {
            $this->add($child, $depth + 1, $teacher, $byParent, $choices);
        }
    }
}
