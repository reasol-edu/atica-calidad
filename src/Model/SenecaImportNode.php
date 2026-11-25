<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One node of the list-item tree a Séneca CSV import wants to end up under the chosen root — a
 * group (leaf, for a "groups" import) or a group with its subjects as children (for a "subjects"
 * import; or a subject with its groups, organised by subject — see SenecaSubjectsLayout). Order
 * matters: children are kept in first-seen CSV order, matching how the resulting list items get
 * their position.
 *
 * The node of each «Unidad»–«Materia» pair of a subjects import also carries the names in the
 * «Profesor/a» column of its rows («Apellidos, Nombre», as Séneca writes them; several in a
 * desdoble), for the import to associate them with the element (see SenecaTeacherMatch).
 */
final class SenecaImportNode
{
    /** @var SenecaImportNode[] */
    private array $children = [];

    /** @var list<string>|null null: not a node the file says who teaches */
    private ?array $teacherNames = null;

    public function __construct(
        public readonly string $name,
    ) {}

    /** Returns the existing child with this name if there's already one, creating and appending it otherwise. */
    public function childNamed(string $name): self
    {
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        $child           = new self($name);
        $this->children[] = $child;

        return $child;
    }

    /** @return SenecaImportNode[] */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Marks this node as one whose teachers come from the file — even with no name, a row with an empty «Profesor/a» says nobody
     * teaches it — and adds $name unless it is empty or already there.
     */
    public function addTeacherName(string $name): void
    {
        $this->teacherNames ??= [];
        if ($name !== '' && !in_array($name, $this->teacherNames, true)) {
            $this->teacherNames[] = $name;
        }
    }

    /** Whether the file says who teaches this node (the «Unidad»–«Materia» pairs of a subjects import with a «Profesor/a» column). */
    public function takesTeachers(): bool
    {
        return $this->teacherNames !== null;
    }

    /** @return list<string> the names as the file writes them, in first-seen order */
    public function getTeacherNames(): array
    {
        return $this->teacherNames ?? [];
    }
}
