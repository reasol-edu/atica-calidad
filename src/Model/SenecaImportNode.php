<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One node of the list-item tree a Séneca CSV import wants to end up under the chosen root — a
 * group (leaf, for a "groups" import) or a group with its subjects as children (for a "subjects"
 * import). Order matters: children are kept in first-seen CSV order, matching how the resulting
 * list items get their position.
 */
final class SenecaImportNode
{
    /** @var SenecaImportNode[] */
    private array $children = [];

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
}
